<?php

// Kern-Variablen fuer Schreibbefehle (nicht Teil eines Treibers, gelten fuer jede Instanz mit Steuerpunkten).
class IHUB_WriteDiag
{
    public static function vars(): array
    {
        return [
            ['ctl_write_fail_count', 'Failed commands (count)', 'I', '', false, 'diag', 'Counts the write commands that did not arrive (after both attempts), since the variable was created'],
            ['ctl_write_fail_time',  'Last failed command',     'I', '~UnixTimestamp', false, 'diag', 'Time of the last write command that did not arrive'],
            ['ctl_write_fail_text',  'Last failure',            'S', '', false, 'diag', 'Ident of the command and reason of the last failure'],
            ['ctl_write_ok_time',    'Last successful command', 'I', '~UnixTimestamp', false, 'diag', 'Time of the last write command that went through without a warning'],
        ];
    }
}
