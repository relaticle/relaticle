<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string BODY_VECTOR = "to_tsvector('simple', coalesce(%s, '') || ' ' || left(coalesce(%s, ''), 65536))";

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('create extension if not exists pg_trgm');

        DB::statement('alter table emails add column if not exists subject_search tsvector, add column if not exists body_search tsvector');

        $this->refreshSubjectVector();
        $this->refreshBodyVectorFromBody();
        $this->refreshBodyVectorFromSnippet();
    }

    private function refreshSubjectVector(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function emails_subject_search_refresh() returns trigger
            language plpgsql as $$
            begin
                new.subject_search := to_tsvector('simple', coalesce(new.subject, ''));
                return new;
            end;
            $$;

            create or replace trigger emails_subject_search_refresh
            before insert or update of subject on emails
            for each row execute function emails_subject_search_refresh();
            SQL);
    }

    private function refreshBodyVectorFromBody(): void
    {
        $vector = sprintf(self::BODY_VECTOR, 'emails.snippet', 'new.body_text');

        DB::unprepared(<<<SQL
            create or replace function emails_body_search_refresh() returns trigger
            language plpgsql as \$\$
            begin
                update emails set body_search = {$vector} where emails.id = new.email_id;
                return null;
            end;
            \$\$;

            create or replace trigger emails_body_search_refresh
            after insert or update of body_text on email_bodies
            for each row execute function emails_body_search_refresh();
            SQL);
    }

    private function refreshBodyVectorFromSnippet(): void
    {
        $vector = sprintf(self::BODY_VECTOR, 'new.snippet', '(select email_bodies.body_text from email_bodies where email_bodies.email_id = new.id)');

        DB::unprepared(<<<SQL
            create or replace function emails_snippet_search_refresh() returns trigger
            language plpgsql as \$\$
            begin
                new.body_search := {$vector};
                return new;
            end;
            \$\$;

            create or replace trigger emails_snippet_search_refresh
            before insert or update of snippet on emails
            for each row execute function emails_snippet_search_refresh();
            SQL);
    }
};
