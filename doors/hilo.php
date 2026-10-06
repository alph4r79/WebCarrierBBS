<?php
/**
 * Example door for WebCarrier BBS: Hi-Lo.
 * A door is a class implementing CarrierDoor. This file returns its registration.
 * Can be used as a template for new doors (see docs/SYSOP_GUIDE.md, section Doors).
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (!class_exists('HiLoDoor')) {
    final class HiLoDoor implements CarrierDoor
    {
        public function start(Engine $e): void
        {
            $e->cls();
            $e->bar($e->L('hilo_title'));
            $e->nl();
            $this->hallOfFame($e);
            $e->nl();
            $this->newGame($e);
        }

        private function newGame(Engine $e): void
        {
            $st = &$e->doorState();
            $st = ['n' => random_int(1, 100), 'try' => 1, 'phase' => 'guess'];
            $e->say('hilo_intro');
            $e->nl(2);
            $e->line(3, $e->L('hilo_guess', 1));
        }

        public function input(Engine $e, string $v): void
        {
            $st = &$e->doorState();
            if (($st['phase'] ?? '') === 'again') {
                if ($e->yes($v, true)) {
                    $e->nl();
                    $this->newGame($e);
                } else {
                    $e->leaveDoor();
                }
                return;
            }
            $g = (int)trim($v);
            if ($g < 1 || $g > 100) {
                $e->line(3, $e->L('hilo_guess', $st['try']));
                return;
            }
            if ($g === $st['n']) {
                $e->say('hilo_win', $st['n'], $st['try']);
                $e->nl();
                $best = $e->doorGet('best');
                if ($best === null || (int)$best > $st['try']) {
                    $e->doorSet('best', (string)$st['try']);
                    $e->say('hilo_best');
                    $e->nl();
                }
                $this->askAgain($e, $st);
                return;
            }
            $e->say($g < $st['n'] ? 'hilo_higher' : 'hilo_lower');
            $e->nl();
            $st['try']++;
            if ($st['try'] > 7) {
                $e->say('hilo_lose', $st['n']);
                $e->nl();
                $this->askAgain($e, $st);
                return;
            }
            $e->line(3, $e->L('hilo_guess', $st['try']));
        }

        private function askAgain(Engine $e, array &$st): void
        {
            $st['phase'] = 'again';
            $e->nl();
            $e->yn($e->L('hilo_again'), true);
        }

        private function hallOfFame(Engine $e): void
        {
            $rows = $e->doorAll('best');
            usort($rows, static fn($a, $b) => (int)$a['v'] <=> (int)$b['v']);
            $e->say('hilo_top');
            $e->nl();
            if (!$rows) {
                $e->say('hilo_none');
                $e->nl();
                return;
            }
            foreach (array_slice($rows, 0, 5) as $i => $r) {
                $e->write('|15' . ($i + 1) . '. |11' . cb_esc(cb_pad((string)($r['handle'] ?? '?'), 22)) . '|07' . (int)$r['v']);
                $e->nl();
            }
        }
    }
}

return [
    'id' => 'hilo',
    'name' => 'Hi-Lo',
    'class' => 'HiLoDoor',
    'description' => 'Guess a number between 1 and 100 in seven tries. Keeps a hall of fame.',
];
