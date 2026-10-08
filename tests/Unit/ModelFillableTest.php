<?php

namespace Tests\Unit;

use App\Models\GroupMember;
use App\Models\User;
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

    /** Security fields changed only by explicit, trusted operations. */
    private const INTENTIONALLY_GUARDED_COLUMNS = [
        User::class => ['is_admin', 'auth_version'],
        // Only ServiceAccessDelivery may attest delivery or revoke a member's access.
        GroupMember::class => [
            'service_invitation_url', 'service_invitation_channel', 'service_invitation_email',
            'service_invitation_provided_at', 'service_access_revoked_at',
        ],
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
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $model = new $class;

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

            $missing = array_diff(
                $columns,
                $fillable,
                self::GLOBALLY_EXCLUDED_COLUMNS,
                self::INTENTIONALLY_GUARDED_COLUMNS[$class] ?? [],
            );

            if (! empty($missing)) {
                $mismatches[$class] = $missing;
            }
        }

        $formatted = collect($mismatches)
            ->map(fn ($cols, $class) => "  {$class}: ".implode(', ', $cols))
            ->implode("\n");

        $this->assertEmpty(
            $mismatches,
            "Colonnes en base absentes de \$fillable (Eloquent les ignore silencieusement lors d'un create()/update()) :\n{$formatted}"
        );
    }

    public function test_privileged_user_columns_remain_guarded_on_create_and_update(): void
    {
        $model = new User;
        foreach (self::INTENTIONALLY_GUARDED_COLUMNS[User::class] as $column) {
            $this->assertTrue(Schema::hasColumn($model->getTable(), $column));
            $this->assertNotContains($column, $model->getFillable());
            $this->assertFalse($model->isFillable($column));
        }

        $user = User::create([
            'name' => 'Synthetic Privilege Attempt',
            'email' => 'protected-columns@example.test',
            'password' => 'Synthetic-password',
            'is_admin' => true,
            'auth_version' => 99,
        ])->refresh();
        $this->assertFalse($user->is_admin);
        $this->assertSame(0, $user->auth_version);

        $user->update(['is_admin' => true, 'auth_version' => 100]);

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertSame(0, $user->fresh()->auth_version);
    }

    public function test_service_delivery_columns_cannot_be_mass_assigned(): void
    {
        $member = new GroupMember;
        $untrusted = [];
        foreach (self::INTENTIONALLY_GUARDED_COLUMNS[GroupMember::class] as $column) {
            $this->assertTrue(Schema::hasColumn($member->getTable(), $column));
            $this->assertFalse($member->isFillable($column));
            $untrusted[$column] = 'untrusted-delivery-attestation';
        }

        $member->fill($untrusted);

        foreach (array_keys($untrusted) as $column) {
            $this->assertArrayNotHasKey($column, $member->getAttributes());
        }
    }
}
