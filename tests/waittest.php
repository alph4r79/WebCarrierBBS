<?php
// Test door: wait(), then a line that Escape may cancel.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

if (!class_exists('WaitTestDoor')) {
    final class WaitTestDoor implements CarrierDoor
    {
        public function start(Engine $e): void
        {
            $st = &$e->doorState();
            $st = ['phase' => 'wait'];
            $e->cls();
            $e->write('|15Wartetest|07');
            $e->nl(2);
            $e->wait(2500, '|07Bitte warten ...');
        }

        public function input(Engine $e, string $v): void
        {
            $st = &$e->doorState();
            if ($st['phase'] === 'wait') {
                $st['phase'] = 'line';
                $e->nl();
                $e->line(20, '|07Eingabe (Esc bricht ab)|08: |15', false, true);
                return;
            }
            if ($st['phase'] === 'line') {
                $e->nl();
                $e->write($e->cancelled() ? '|12ABBRUCH|07' : '|10Eingabe: ' . cb_esc($v) . '|07');
                $e->nl();
                $st['phase'] = 'end';
                $e->pause();
                return;
            }
            $e->leaveDoor();
        }
    }
}

return ['id' => 'waittest', 'name' => 'Wartetest', 'class' => 'WaitTestDoor', 'description' => 'Test', 'version' => '1.0'];
