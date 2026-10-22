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
                'intro' => '|07Ich denke an eine Zahl zwischen |151|07 und |15100|07. Du hast |157|07 Versuche. |15Esc|07 gibt auf.',
                'guess' => '|07Versuch {1} von 7, dein Tipp|08: |15',
                'higher' => '|11Höher!|07',
                'lower' => '|11Niedriger!|07',
                'win' => '|10Richtig! Du hast die {1} in {2} Versuchen gefunden.|07',
                'lose' => '|12Keine Versuche mehr. Die Zahl war {1}.|07',
                'giveup' => '|12Aufgegeben. Die Zahl war {1}.|07',
                'best' => '|14Neuer persönlicher Rekord!|07',
                'again' => '|07Nochmal spielen',
                'top' => '|03Ruhmeshalle (wenigste Versuche)',
                'none' => '|07Noch hat niemand gewonnen.',
                'try1' => '{1} Versuch',
                'tries' => '{1} Versuche',
            ],
            'en' => [
                'title' => 'Hi-Lo, the number guessing door',
                'intro' => '|07I am thinking of a number between |151|07 and |15100|07. You have |157|07 tries. |15Esc|07 gives up.',
                'guess' => '|07Try {1} of 7, your guess|08: |15',
                'higher' => '|11Higher!|07',
                'lower' => '|11Lower!|07',
                'win' => '|10Correct! You found {1} in {2} tries.|07',
                'lose' => '|12Out of tries. The number was {1}.|07',
                'giveup' => '|12You gave up. The number was {1}.|07',
                'best' => '|14New personal best!|07',
                'again' => '|07Play again',
                'top' => '|03Hall of fame (fewest tries)',
                'none' => '|07Nobody has won yet.',
                'try1' => '{1} try',
                'tries' => '{1} tries',
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

        /** True if the caller cancelled the line with Escape. Boards before 1.10.0 have no cancelled(). */
        private function cancelled(Engine $e): bool
        {
            return method_exists($e, 'cancelled') && $e->cancelled();
        }

        /** Line for the next guess, Escape cancels it. */
        private function askGuess(Engine $e, int $try): void
        {
            $e->line(3, $this->t($e, 'guess', $try), false, true);
        }

        /** True on boards with the high score list (1.6.0 and newer). Older ones keep the best value in door_data. */
        private function hasList(Engine $e): bool
        {
            return method_exists($e, 'doorScore') && function_exists('cb_door_top') && function_exists('cb_door_rank');
        }

        private function doorId(Engine $e): string
        {
            return (string)($e->S['door'] ?? 'hilo');
        }

        /** Best result of the caller: from the high score list, on old boards from door_data. Null if none. */
        private function best(Engine $e): ?int
        {
            if ($this->hasList($e) && $e->user) {
                $rank = cb_door_rank($this->doorId($e), 'low', (int)$e->user['id']);
                if ($rank !== null) {
                    return (int)$rank[1]['value'];
                }
            }
            $old = $e->doorGet('best');
            return $old === null ? null : (int)$old;
        }

        /** New best result: into the high score list, only boards without it still use door_data. */
        private function saveBest(Engine $e, int $n): void
        {
            if ($this->hasList($e)) {
                $e->doorScore($n, $this->t($e, $n === 1 ? 'try1' : 'tries', $n));
            } else {
                $e->doorSet('best', (string)$n);
            }
        }

        /**
         * A best value from door_data that is not in the list yet (board without the takeover of 1.11.0)
         * goes into the list, a better entry there is kept.
         */
        private function takeOverBest(Engine $e): void
        {
            if (!$this->hasList($e) || !$e->user) {
                return;
            }
            $old = $e->doorGet('best');
            if ($old === null || (int)$old < 1) {
                return;
            }
            $rank = cb_door_rank($this->doorId($e), 'low', (int)$e->user['id']);
            if ($rank === null || (int)$rank[1]['value'] > (int)$old) {
                $this->saveBest($e, (int)$old);
            }
        }

        public function start(Engine $e): void
        {
            $this->takeOverBest($e);
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
            $this->askGuess($e, 1);
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
            // Escape is an abort, not an empty line: the game ends, nothing is counted
            if ($this->cancelled($e)) {
                $e->write($this->t($e, 'giveup', $st['n']));
                $e->nl();
                $this->askAgain($e, $st);
                return;
            }
            $g = (int)trim($v);
            if ($g < 1 || $g > 100) {
                $this->askGuess($e, $st['try']);
                return;
            }
            if ($g === $st['n']) {
                $e->write($this->t($e, 'win', $st['n'], $st['try']));
                $e->nl();
                $best = $this->best($e);
                if ($best === null || $best > $st['try']) {
                    $this->saveBest($e, (int)$st['try']);
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
            $this->askGuess($e, $st['try']);
        }

        private function askAgain(Engine $e, array &$st): void
        {
            $st['phase'] = 'again';
            $e->nl();
            $e->yn($this->t($e, 'again'), true);
        }

        /** Top 5 from the high score list of the board, the same order as the box above the doors. */
        private function hallOfFame(Engine $e): void
        {
            if ($this->hasList($e)) {
                $rows = array_map(static fn($r) => ['handle' => $r['handle'], 'v' => $r['value']], cb_door_top($this->doorId($e), 'low', 5));
            } else {
                $rows = $e->doorAll('best');
                usort($rows, static fn($a, $b) => (int)$a['v'] <=> (int)$b['v']);
            }
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
    'version' => '1.3.1',
    'score' => 'low',
];
