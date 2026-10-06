<?php

namespace App\Realtime\Observability;

class RealtimeObservedRatchetApp extends \Ratchet\App
{
    public function observeTransport(RealtimeTransportObserver $observer): void
    {
        $this->_server->app = new RealtimeTransportComponent($this->_server->app, $observer);
    }
}
