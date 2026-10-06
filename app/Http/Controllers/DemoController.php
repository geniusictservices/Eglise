<?php

namespace App\Http\Controllers;

use App\Services\DemoSandbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/** La démo publique : chaque visiteur reçoit sa copie de la communauté de démonstration. */
class DemoController extends Controller
{
    /** Les comptes de la démo, pour essayer chaque rôle : numéro de la personne => [nom, rôle, ce qu'on y voit]. */
    public const ACCOUNTS = [
        1 => ['Jean-Paul Kambale', 'Administrateur du siège', 'Tout : la hiérarchie, les utilisateurs, la consolidation'],
        6 => ['Pasteur Daniel Paluku', 'Pasteur de Himbi', 'Le tableau de bord, les approbations, le suivi pastoral'],
        7 => ['Furaha Masika', 'Trésorière de Himbi', 'Les caisses, la collecte du culte, les dépenses, la paie'],
        8 => ['Esther Kavira', 'Secrétaire de Himbi', 'Le registre, les documents, le site vitrine'],
        5 => ['Pasteur Amani Bahati', 'Responsable de la région', 'La consolidation des paroisses, les quotes-parts'],
        13 => ['Grâce Kambale', 'Membre', 'Son espace : sa carte, ses dons, ses demandes'],
    ];

    public function show()
    {
        return view('site.demo', ['days' => app(DemoSandbox::class)->days(), 'accounts' => self::ACCOUNTS]);
    }

    public function start(Request $request, DemoSandbox $sandbox)
    {
        // Champ piège : invisible pour une personne, rempli par les robots.
        if (filled($request->input('site_web'))) {
            return redirect()->route('demo.show');
        }
        @set_time_limit(120);
        try {
            [, $admin] = $sandbox->create();
        } catch (RuntimeException $e) {
            return redirect()->route('demo.show')->with('status', $e->getMessage());
        }
        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('demo.welcome');
    }
}
