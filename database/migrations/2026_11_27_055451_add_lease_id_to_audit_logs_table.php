<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ties each audit entry to the tenancy it is about (PTR-35). No foreign key:
 * a tenant's history outlives the lease row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', static function (Blueprint $table): void {
            $table->unsignedBigInteger('lease_id')->nullable()->after('auditable_id');
            $table->index(['lease_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });

        DB::table('audit_logs')->chunkById(500, static function ($rows): void {
            foreach ($rows as $row) {
                $leaseId = AuditLog::leaseIdFor(
                    (string) $row->auditable_type,
                    (int) $row->auditable_id,
                    (array) json_decode((string) ($row->new_values ?? 'null'), true),
                    (array) json_decode((string) ($row->old_values ?? 'null'), true),
                );

                if ($leaseId !== null) {
                    DB::table('audit_logs')->where('id', $row->id)->update(['lease_id' => $leaseId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', static function (Blueprint $table): void {
            $table->dropIndex(['lease_id', 'created_at']);
            $table->dropIndex(['actor_user_id', 'created_at']);
            $table->dropColumn('lease_id');
        });
    }
};
