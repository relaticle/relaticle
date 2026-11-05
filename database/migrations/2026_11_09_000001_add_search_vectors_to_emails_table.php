<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create extension if not exists btree_gin');

        DB::statement('alter table emails add column if not exists subject_search tsvector, add column if not exists body_search tsvector');

        $this->createVectorFunctions();
        $this->refreshSubjectVector();
        $this->refreshBodyVectorFromBody();
        $this->refreshBodyVectorFromSnippet();
    }

    private function createVectorFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function email_subject_vector(subject text) returns tsvector
            language sql immutable parallel safe as $$
                select to_tsvector('simple'::regconfig, left(coalesce(subject, ''), 65536))
            $$;

            create or replace function email_body_vector(snippet text, body_text text) returns tsvector
            language sql immutable parallel safe as $$
                select to_tsvector('simple'::regconfig, coalesce(snippet, '') || ' ' || left(coalesce(body_text, ''), 65536))
            $$;
            SQL);
    }

    private function refreshSubjectVector(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function emails_subject_search_refresh() returns trigger
            language plpgsql as $$
            begin
                new.subject_search := email_subject_vector(new.subject);
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
        DB::unprepared(<<<'SQL'
            create or replace function emails_body_search_refresh() returns trigger
            language plpgsql as $$
            begin
                update emails
                set body_search = email_body_vector(emails.snippet, new.body_text)
                where emails.id = coalesce(new.email_id, old.email_id);
                return null;
            end;
            $$;

            create or replace trigger emails_body_search_refresh
            after insert or update of body_text or delete on email_bodies
            for each row execute function emails_body_search_refresh();
            SQL);
    }

    private function refreshBodyVectorFromSnippet(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function emails_snippet_search_refresh() returns trigger
            language plpgsql as $$
            begin
                new.body_search := email_body_vector(
                    new.snippet,
                    (select email_bodies.body_text from email_bodies where email_bodies.email_id = new.id)
                );
                return new;
            end;
            $$;

            create or replace trigger emails_snippet_search_refresh
            before insert or update of snippet on emails
            for each row execute function emails_snippet_search_refresh();
            SQL);
    }
};
