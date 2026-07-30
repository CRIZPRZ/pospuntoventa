import 'dotenv/config'
import express from 'express'
import cors from 'cors'
import fs from 'node:fs'
import path from 'node:path'
import P from 'pino'
import QRCode from 'qrcode'
import makeWASocket, {
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  generateWAMessageFromContent,
  proto,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys'

const app = express()
const port = Number(process.env.PORT || 3025)
const host = process.env.HOST || '127.0.0.1'
const sessionsDir = process.env.SESSIONS_DIR || './sessions'
const token = process.env.BAILEYS_AUTH_TOKEN || ''
const logger = P({ level: process.env.LOG_LEVEL || 'info' })
const sessions = new Map()
const startingSessions = new Map()
const messageWaiters = new Map()

process.on('unhandledRejection', (error) => {
  logger.error({ error }, 'Unhandled rejection in Baileys service')
})

process.on('uncaughtException', (error) => {
  logger.error({ error }, 'Uncaught exception in Baileys service')
})

app.use(cors())
app.use(express.json({ limit: '1mb' }))

app.use((req, res, next) => {
  if (!token) return next()
  const header = req.headers.authorization || ''
  if (header === `Bearer ${token}`) return next()
  return res.status(401).json({ message: 'No autorizado' })
})

function sessionPath(sessionKey) {
  return path.join(sessionsDir, sessionKey)
}

function clearStoredSession(sessionKey) {
  sessions.delete(sessionKey)
  fs.rmSync(sessionPath(sessionKey), { recursive: true, force: true })
}

async function startSocket(sessionKey, meta = {}) {
  if (!sessionKey) throw new Error('session_key requerido')

  if (sessions.has(sessionKey)) {
    return sessions.get(sessionKey)
  }

  if (startingSessions.has(sessionKey)) {
    return startingSessions.get(sessionKey)
  }

  const starter = createSocket(sessionKey, meta)
    .finally(() => startingSessions.delete(sessionKey))

  startingSessions.set(sessionKey, starter)
  return starter
}

async function createSocket(sessionKey, meta = {}) {

  fs.mkdirSync(sessionPath(sessionKey), { recursive: true })
  const { state, saveCreds } = await useMultiFileAuthState(sessionPath(sessionKey))
  const { version } = await fetchLatestBaileysVersion()
  const current = {
    key: sessionKey,
    status: 'qr_pending',
    qr: null,
    qrImage: null,
    phoneNumber: meta.phone_number || '',
    displayName: meta.business_name || '',
    lastError: '',
    socket: null,
    qrWaiters: [],
  }

  const socket = makeWASocket({
    auth: state,
    version,
    browser: Browsers.macOS('Desktop'),
    printQRInTerminal: false,
    logger,
  })

  current.socket = socket
  sessions.set(sessionKey, current)

  socket.ev.on('creds.update', saveCreds)
  socket.ev.on('messages.update', (updates) => {
    for (const entry of updates || []) {
      const id = entry?.key?.id
      if (!id) continue
      const waiter = messageWaiters.get(id)
      if (!waiter) continue
      clearTimeout(waiter.timer)
      messageWaiters.delete(id)
      waiter.resolve(normalizeMessageStatus(entry.update?.status))
    }
  })
  socket.ev.on('connection.update', async (update) => {
    if (update.qr) {
      current.qr = update.qr
      current.qrImage = await QRCode.toDataURL(update.qr)
      current.status = 'qr_pending'
      current.lastError = ''
      logger.info({ sessionKey }, 'QR generated')
      current.qrWaiters.splice(0).forEach((resolve) => resolve(current))
    }

    if (update.connection === 'open') {
      current.status = 'connected'
      current.qr = null
      current.qrImage = null
      current.phoneNumber = socket.user?.id || current.phoneNumber
      current.displayName = socket.user?.name || current.displayName
      current.lastError = ''
      current.qrWaiters.splice(0).forEach((resolve) => resolve(current))
    }

    if (update.connection === 'close') {
      const code = update.lastDisconnect?.error?.output?.statusCode
      const message = update.lastDisconnect?.error?.message || ''
      const shouldResetSession = message.includes('Connection Failure')
      const shouldReconnect = code !== DisconnectReason.loggedOut && !shouldResetSession
      current.status = shouldReconnect ? 'reconnecting' : 'disconnected'
      current.lastError = message
      current.qrWaiters.splice(0).forEach((resolve) => resolve(current))
      sessions.delete(sessionKey)
    }
  })

  return current
}

function waitForQr(session, timeoutMs = 15000) {
  if (session.qrImage || session.status === 'connected') {
    return Promise.resolve(session)
  }

  return new Promise((resolve) => {
    const timer = setTimeout(() => {
      const index = session.qrWaiters.indexOf(done)
      if (index >= 0) session.qrWaiters.splice(index, 1)
      resolve(session)
    }, timeoutMs)

    function done(nextSession) {
      clearTimeout(timer)
      resolve(nextSession)
    }

    session.qrWaiters.push(done)
  })
}

function waitForConnected(session, timeoutMs = 20000) {
  if (session.status === 'connected') {
    return Promise.resolve(session)
  }

  return new Promise((resolve) => {
    const timer = setTimeout(() => {
      const index = session.qrWaiters.indexOf(done)
      if (index >= 0) session.qrWaiters.splice(index, 1)
      resolve(session)
    }, timeoutMs)

    function done(nextSession) {
      if (!['connected', 'qr_pending', 'disconnected'].includes(nextSession.status)) return
      clearTimeout(timer)
      resolve(nextSession)
    }

    session.qrWaiters.push(done)
  })
}

function withTimeout(promise, timeoutMs, message) {
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      setTimeout(() => reject(new Error(message)), timeoutMs)
    }),
  ])
}

function normalizeMessageStatus(status) {
  if (typeof status === 'string') return status
  const map = {
    0: 'PENDING',
    1: 'SERVER_ACK',
    2: 'DELIVERY_ACK',
    3: 'READ',
    4: 'PLAYED',
  }
  return map[status] || 'PENDING'
}

function isDeliveredStatus(status) {
  return ['DELIVERY_ACK', 'READ', 'PLAYED'].includes(status)
}

function waitForMessageStatus(messageId, initialStatus = 'PENDING', timeoutMs = 10000) {
  if (!messageId || isDeliveredStatus(initialStatus)) {
    return Promise.resolve(initialStatus)
  }

  return new Promise((resolve) => {
    let latestStatus = initialStatus || 'PENDING'
    const timer = setTimeout(() => {
      messageWaiters.delete(messageId)
      resolve(latestStatus)
    }, timeoutMs)
    messageWaiters.set(messageId, {
      timer,
      resolve: (nextStatus) => {
        latestStatus = nextStatus || latestStatus
        if (!isDeliveredStatus(latestStatus)) return
        clearTimeout(timer)
        messageWaiters.delete(messageId)
        resolve(latestStatus)
      },
    })
  })
}

function recipientCandidates(rawTo) {
  const digits = String(rawTo || '').replace(/\D+/g, '')
  if (!digits) return []

  const candidates = [digits]
  if (digits.length === 10) {
    candidates.unshift(`52${digits}`)
    candidates.push(`521${digits}`)
  }
  if (digits.length === 12 && digits.startsWith('52')) {
    candidates.push(`521${digits.slice(2)}`)
  }
  if (digits.length === 13 && digits.startsWith('521')) {
    candidates.unshift(`52${digits.slice(3)}`)
  }

  return [...new Set(candidates)]
}

async function resolveRecipientJid(socket, rawTo) {
  const candidates = recipientCandidates(rawTo)
  if (!candidates.length) return ''

  try {
    const checks = await withTimeout(
      socket.onWhatsApp(...candidates),
      10000,
      'No se pudo validar el número en WhatsApp.'
    )
    const match = checks.find((entry) => entry.exists)
    if (match?.jid) return match.jid
  } catch (error) {
    logger.warn({ error, candidates }, 'Could not validate WhatsApp recipient, using first candidate')
  }

  return `${candidates[0]}@s.whatsapp.net`
}

async function sendUrlButton(socket, jid, body, buttonText, url) {
  const msg = generateWAMessageFromContent(jid, {
    viewOnceMessage: {
      message: {
        interactiveMessage: proto.Message.InteractiveMessage.create({
          body: proto.Message.InteractiveMessage.Body.create({ text: body }),
          footer: proto.Message.InteractiveMessage.Footer.create({ text: 'EventPOS' }),
          nativeFlowMessage: proto.Message.InteractiveMessage.NativeFlowMessage.create({
            buttons: [
              {
                name: 'cta_url',
                buttonParamsJson: JSON.stringify({
                  display_text: buttonText,
                  url,
                  merchant_url: url,
                }),
              },
            ],
          }),
        }),
      },
    },
  }, {})

  await socket.relayMessage(jid, msg.message, { messageId: msg.key.id })
  return { key: msg.key, message: msg.message }
}

function hasStoredSession(sessionKey) {
  return fs.existsSync(sessionPath(sessionKey))
}

function publicSession(session) {
  return {
    status: session?.status || 'disconnected',
    qr: session?.qr || null,
    image: session?.qrImage || null,
    qr_image: session?.qrImage || null,
    phone_number: session?.phoneNumber || '',
    display_name: session?.displayName || '',
    last_error: session?.lastError || '',
  }
}

app.post('/sessions/start', async (req, res) => {
  try {
    const session = await waitForQr(await startSocket(req.body.session_key, req.body))
    res.json({ qr: publicSession(session), status: session.status })
  } catch (error) {
    res.status(422).json({ message: error.message })
  }
})

app.get('/sessions/:sessionKey/qr', async (req, res) => {
  try {
    let session = await waitForQr(await startSocket(req.params.sessionKey))
    if (!session.qrImage && session.status === 'disconnected' && session.lastError.includes('Connection Failure')) {
      clearStoredSession(req.params.sessionKey)
      session = await waitForQr(await startSocket(req.params.sessionKey))
    }
    res.json(publicSession(session))
  } catch (error) {
    res.status(422).json({ message: error.message })
  }
})

app.get('/sessions/:sessionKey/status', async (req, res) => {
  try {
    const session = sessions.get(req.params.sessionKey)
      || (hasStoredSession(req.params.sessionKey) ? await waitForConnected(await startSocket(req.params.sessionKey), 8000) : null)
    res.json(publicSession(session))
  } catch (error) {
    res.status(422).json({ message: error.message })
  }
})

app.get('/debug/resolve/:sessionKey/:to', async (req, res) => {
  try {
    const session = await waitForConnected(await startSocket(req.params.sessionKey))
    if (session.status !== 'connected' || !session.socket) {
      return res.status(422).json({ message: 'La sesión de WhatsApp no está conectada.' })
    }

    const candidates = recipientCandidates(req.params.to)
    const checks = candidates.length
      ? await withTimeout(session.socket.onWhatsApp(...candidates), 10000, 'No se pudo validar el número en WhatsApp.')
      : []
    const resolved = await resolveRecipientJid(session.socket, req.params.to)
    res.json({ candidates, checks, resolved })
  } catch (error) {
    res.status(422).json({ message: error.message })
  }
})

app.post('/messages/send', async (req, res) => {
  try {
    const session = await waitForConnected(await startSocket(req.body.session_key))
    if (session.status !== 'connected' || !session.socket) {
      return res.status(422).json({ message: 'La sesión de WhatsApp no está conectada.' })
    }

    const to = String(req.body.to || '').replace(/\D+/g, '')
    const message = String(req.body.message || '')
    if (!to || !message) {
      return res.status(422).json({ message: 'Destino y mensaje son requeridos.' })
    }

    const jid = await resolveRecipientJid(session.socket, to)
    if (!jid) {
      return res.status(422).json({ message: 'El número destino no es válido para WhatsApp.' })
    }

    const type = String(req.body.type || 'text')
    let response
    let sentType = 'text'

    if (type === 'button_url' && req.body.url && req.body.button_text) {
      try {
        response = await withTimeout(
          sendUrlButton(session.socket, jid, message, String(req.body.button_text), String(req.body.url)),
          45000,
          'WhatsApp tardó demasiado en confirmar el envío. Revisa el estado de la sesión e intenta de nuevo.'
        )
        sentType = 'button_url'
      } catch (error) {
        logger.warn({ error }, 'URL button failed, falling back to text link')
        response = await withTimeout(
          session.socket.sendMessage(jid, { text: `${message}\n\n${req.body.button_text}: ${req.body.url}` }),
          45000,
          'WhatsApp tardó demasiado en confirmar el envío. Revisa el estado de la sesión e intenta de nuevo.'
        )
      }
    } else {
      response = await withTimeout(
        session.socket.sendMessage(jid, { text: message }),
        45000,
        'WhatsApp tardó demasiado en confirmar el envío. Revisa el estado de la sesión e intenta de nuevo.'
      )
    }

    const initialStatus = normalizeMessageStatus(response?.status)
    const deliveryStatus = await waitForMessageStatus(response?.key?.id, initialStatus)

    res.json({
      status: isDeliveredStatus(deliveryStatus) ? 'sent' : 'pending',
      delivery_status: deliveryStatus,
      type: sentType,
      to: jid,
      response,
    })
  } catch (error) {
    res.status(422).json({ message: error.message })
  }
})

app.post('/sessions/:sessionKey/logout', async (req, res) => {
  try {
    const session = sessions.get(req.params.sessionKey)
    if (session?.socket) {
      await session.socket.logout()
    }
    sessions.delete(req.params.sessionKey)
    res.json({ status: 'disconnected' })
  } catch (error) {
    res.status(422).json({ message: error.message })
  }
})

app.listen(port, host, () => {
  logger.info(`EventPOS Baileys service listening on ${host}:${port}`)
})
