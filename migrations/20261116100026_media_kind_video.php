<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Mediathek: neue Art „video“ für hochgeladene MP4-Videos. */
final class MediaKindVideo extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE media MODIFY kind ENUM('image','document','video') NOT NULL DEFAULT 'image'");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM media WHERE kind = 'video'");
        $this->execute("ALTER TABLE media MODIFY kind ENUM('image','document') NOT NULL DEFAULT 'image'");
    }
}
