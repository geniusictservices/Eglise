<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Clients;
use App\Core\BaseConsole;

/** Journal de la console (200 dernières actions) et messages envoyés par la console (relances, essais). */
final class JournalController extends Base
{
    public function index(): void
    {
        $this->admin();
        $vue = ($_GET['vue'] ?? '') === 'messages' ? 'messages' : 'actions';
        $this->vue('journal', [
            'titre' => t('console.journal.titre'),
            'vueChoisie' => $vue,
            'journal' => $vue === 'actions' ? Clients::journal(null, 200) : [],
            'messages' => $vue === 'messages' ? BaseConsole::tous(
                'SELECT m.*, c.nom AS client_nom FROM messages m LEFT JOIN clients c ON c.id = m.client_id ORDER BY m.id DESC LIMIT 200'
            ) : [],
        ]);
    }
}
