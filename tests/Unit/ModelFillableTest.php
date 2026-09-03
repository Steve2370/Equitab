<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModelFillableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Colonnes gérées par Eloquent/Laravel lui-même (pas par nos
     * create()/update() applicatifs) — jamais censées être dans $fillable.
     */
    private const GLOBALLY_EXCLUDED_COLUMNS = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'email_verified_at', 'remember_token',
    ];

    /**
     * Ce test existe parce que deux bugs de production ont été causés par
     * la même erreur, à deux reprises : une migration ajoute une colonne à
     * une table, mais personne ne pense à l'ajouter au $fillable du
     * modèle Eloquent correspondant. Eloquent ignore alors silencieusement
     * cette colonne à chaque create()/update()/updateOrCreate() — sans
     * erreur, sans warning. Ça a fait perdre stripe_subscription_id
     * (membres bloqués "en attente" malgré un paiement Stripe réussi) et
     * platform_fee_amount (gains Equitab affichés à 0$) pendant des
     * semaines avant d'être détecté manuellement.
     *
     * Ce test échoue dès qu'un modèle a une colonne en base absente de
     * son $fillable, pour qu'un oubli futur casse la suite de tests au
     * lieu de casser silencieusement la production.
     */
    public function test_every_model_fillable_covers_its_mass_assignable_columns(): void
    {
        $modelFiles = glob(app_path('Models/*.php'));
        $mismatches = [];

        foreach ($modelFiles as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $model = new $class();

            // Un modèle explicitement non protégé (guarded = []) autorise
            // volontairement tout — rien à vérifier pour lui.
            if ($model->getGuarded() === []) {
                continue;
            }

            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $fillable = $model->getFillable();

            $missing = array_diff($columns, $fillable, self::GLOBALLY_EXCLUDED_COLUMNS);

            if (! empty($missing)) {
                $mismatches[$class] = $missing;
            }
        }

        $formatted = collect($mismatches)
            ->map(fn ($cols, $class) => "  {$class}: " . implode(', ', $cols))
            ->implode("\n");

        $this->assertEmpty(
            $mismatches,
            "Colonnes en base absentes de \$fillable (Eloquent les ignore silencieusement lors d'un create()/update()) :\n{$formatted}"
        );
    }
}
