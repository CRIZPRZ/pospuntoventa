<?php

namespace Tests\Feature;

use App\Events\VentaCompletada;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EventListenerRegistrationTest extends TestCase
{
    public function test_sale_listeners_are_registered_once(): void
    {
        $listeners = Event::getListeners(VentaCompletada::class);

        $this->assertCount(1, $listeners);
    }
}
