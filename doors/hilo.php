<?php
/**
 * Example door for WebCarrier BBS: Hi-Lo.
 * A door is a class implementing CarrierDoor. This file returns its registration.
 * Small template for new doors, see docs/DOOR_GUIDE.md.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (!class_exists('HiLoDoor')) {
    final class HiLoDoor implements CarrierDoor
    {
        private const TEXTS = [
            'de' => [
                'title' => 'Hi-Lo, die Zahlenrate-Door',
                'intro' => '|07Ich denke an eine Zahl zwischen |151|07 und |15100|07. Du hast |157|07 Versuche.',
                'guess' => '|07Versuch {1} von 7, dein Tipp|08: |15',
                'higher' => '|11Höher!|07',
                'lower' => '|11Niedriger!|07',
                'win' => '|10Richtig! Du hast die {1} in {2} Versuchen gefunden.|07',
                'lose' => '|12Keine Versuche mehr. Die Zahl war {1}.|07',
                'best' => '|14Neuer persönlicher Rekord!|07',
                'again' => '|07Nochmal spielen',
                'top' => '|03Ruhmeshalle (wenigste Versuche)',
                'none' => '|07Noch hat niemand gewonnen.',
            ],
            'en' => [
                'title' => 'Hi-Lo, the number guessing door',
                'intro' => '|07I am thinking of a number between |151|07 and |15100|07. You have |157|07 tries.',
                'guess' => '|07Try {1} of 7, your guess|08: |15',
                'higher' => '|11Higher!|07',
                'lower' => '|11Lower!|07',
                'win' => '|10Correct! You found {1} in {2} tries.|07',
                'lose' => '|12Out of tries. The number was {1}.|07',
                'best' => '|14New personal best!|07',
                'again' => '|07Play again',
                'top' => '|03Hall of fame (fewest tries)',
                'none' => '|07Nobody has won yet.',
            ],
        ];

        /** Text in the language of the board, with macros, {1} {2} ... replaced afterwards. */
        private function t(Engine $e, string $key, int|string ...$args): string
        {
            $s = $e->macros((self::TEXTS[Lang::$code] ?? self::TEXTS['en'])[$key]);
            foreach ($args as $i => $a) {
                $s = str_replace('{' . ($i + 1) . '}', (string)$a, $s);
            }
            return $s;
        }

        public function start(Engine $e): void
        {
            $e->cls();
            $e->bar($this->t($e, 'title'));
            $e->nl();
            $this->hallOfFame($e);
            $e->nl();
            $this->newGame($e);
        }

        private function newGame(Engine $e): void
        {
            $st = &$e->doorState();
            $st = ['n' => random_int(1, 100), 'try' => 1, 'phase' => 'guess'];
            $e->write($this->t($e, 'intro'));
            $e->nl(2);
            $e->line(3, $this->t($e, 'guess', 1));
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
                $e->line(3, $this->t($e, 'guess', $st['try']));
                return;
            }
            if ($g === $st['n']) {
                $e->write($this->t($e, 'win', $st['n'], $st['try']));
                $e->nl();
                $best = $e->doorGet('best');
                if ($best === null || (int)$best > $st['try']) {
                    $e->doorSet('best', (string)$st['try']);
                    $e->write($this->t($e, 'best'));
                    $e->nl();
                }
                $this->askAgain($e, $st);
                return;
            }
            $e->write($this->t($e, $g < $st['n'] ? 'higher' : 'lower'));
            $e->nl();
            $st['try']++;
            if ($st['try'] > 7) {
                $e->write($this->t($e, 'lose', $st['n']));
                $e->nl();
                $this->askAgain($e, $st);
                return;
            }
            $e->line(3, $this->t($e, 'guess', $st['try']));
        }

        private function askAgain(Engine $e, array &$st): void
        {
            $st['phase'] = 'again';
            $e->nl();
            $e->yn($this->t($e, 'again'), true);
        }

        private function hallOfFame(Engine $e): void
        {
            $rows = $e->doorAll('best');
            usort($rows, static fn($a, $b) => (int)$a['v'] <=> (int)$b['v']);
            $e->write($this->t($e, 'top'));
            $e->nl();
            if (!$rows) {
                $e->write($this->t($e, 'none'));
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
    'version' => '1.1.0',
];
