<?php

declare(strict_types=1);

namespace App\Migration;

use Lyrasoft\Throttle\Entity\LockKey;
use Windwalker\Core\Migration\AbstractMigration;
use Windwalker\Core\Migration\MigrateDown;
use Windwalker\Core\Migration\MigrateUp;
use Windwalker\Database\Schema\Schema;

return new /** 2025070307320001_LockKeyInit */ class extends AbstractMigration {
    #[MigrateUp]
    public function up(): void
    {
        $this->createTable(
            LockKey::class,
            function (Schema $schema) {
                $schema->varchar('id')->length(64)->primary(true);
                $schema->varchar('token')->length(44);
                $schema->integer('expiration');
            }
        );
    }

    #[MigrateDown]
    public function down(): void
    {
        $this->dropTables(LockKey::class);
    }
};
