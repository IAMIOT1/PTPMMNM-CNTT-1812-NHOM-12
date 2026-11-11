-- Xóa bảng cũ nếu tồn tại để làm sạch
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS reviews CASCADE;
DROP TABLE IF EXISTS release_sync_history CASCADE;
DROP TABLE IF EXISTS release_snapshots CASCADE;
DROP TABLE IF EXISTS translations CASCADE;
DROP TABLE IF EXISTS translation_locks CASCADE;
DROP TABLE IF EXISTS strings CASCADE;
DROP TABLE IF EXISTS source_versions CASCADE;
DROP TABLE IF EXISTS locales CASCADE;
DROP TABLE IF EXISTS project_members CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS projects CASCADE;
DROP SEQUENCE IF EXISTS users_id_seq, projects_id_seq, source_versions_id_seq, translations_id_seq;

CREATE SEQUENCE users_id_seq START WITH 10000001;
CREATE SEQUENCE projects_id_seq START WITH 10000001;
CREATE SEQUENCE source_versions_id_seq START WITH 10000001;
CREATE SEQUENCE translations_id_seq START WITH 10000001;

CREATE TABLE users (
    id INTEGER NOT NULL DEFAULT nextval('users_id_seq') PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    verification_code VARCHAR(6),
    is_verified BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD CONSTRAINT users_role_check
    CHECK (role IN ('admin', 'user'));

CREATE UNIQUE INDEX users_username_lower_uidx ON users (lower(username));
CREATE UNIQUE INDEX users_email_lower_uidx ON users (lower(email));

CREATE TABLE password_reset_tokens (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    used_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX password_reset_tokens_user_idx ON password_reset_tokens (user_id);
CREATE INDEX password_reset_tokens_expiry_idx ON password_reset_tokens (expires_at)
    WHERE used_at IS NULL;

-- 1. Bảng Dự án
CREATE TABLE projects (
    id INTEGER NOT NULL DEFAULT nextval('projects_id_seq') PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    created_by INT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX projects_created_by_idx ON projects (created_by);

CREATE TABLE project_members (
    project_id INT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_in_project VARCHAR(20) NOT NULL
        CHECK (role_in_project IN ('Team Lead', 'Translator', 'Reviewer')),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id)
);

-- 2. Bảng Phiên bản nguồn (mỗi lần import JSON là một version)
CREATE TABLE source_versions (
    id INTEGER NOT NULL DEFAULT nextval('source_versions_id_seq') PRIMARY KEY,
    project_id INT REFERENCES projects(id) ON DELETE CASCADE,
    version_number VARCHAR(50) NOT NULL,
    source_url VARCHAR(2048),
    source_html TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    frozen_at TIMESTAMP WITH TIME ZONE,
    frozen_by_user_id INT REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (project_id, version_number)
);

-- 3. Bảng Các khóa văn bản gốc (đã làm phẳng từ JSON)
CREATE TABLE strings (
    id SERIAL PRIMARY KEY,
    project_id INT REFERENCES projects(id) ON DELETE CASCADE NOT NULL,
    source_version_id INT REFERENCES source_versions(id) ON DELETE CASCADE,
    key_path TEXT NOT NULL,
    source_text TEXT NOT NULL,
    UNIQUE (source_version_id, key_path)
);

CREATE TABLE source_version_changes (
    id BIGSERIAL PRIMARY KEY,
    source_version_id INT NOT NULL REFERENCES source_versions(id) ON DELETE CASCADE,
    key_path TEXT NOT NULL,
    change_type VARCHAR(10) NOT NULL CHECK (change_type IN ('added', 'modified', 'deleted')),
    old_text TEXT,
    new_text TEXT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (source_version_id, key_path)
);

-- Supported translation locales
CREATE TABLE locales (
    code VARCHAR(10) PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

INSERT INTO locales (code, name) VALUES
('vi', 'Tiếng Việt'),
('en', 'English'),
('ja', 'Japanese'),
('zh', '中文'),
('ko', '한국어'),
('fr', 'Français'),
('de', 'Deutsch'),
('es', 'Español');

CREATE TABLE project_locales (
    id BIGSERIAL PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    version_id INTEGER REFERENCES source_versions(id) ON DELETE CASCADE,
    locale_code VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
    activated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX project_locales_project_global_uidx
    ON project_locales (project_id, locale_code)
    WHERE version_id IS NULL;
CREATE UNIQUE INDEX project_locales_project_version_uidx
    ON project_locales (project_id, version_id, locale_code)
    WHERE version_id IS NOT NULL;
CREATE INDEX project_locales_project_idx
    ON project_locales (project_id, version_id, locale_code);

-- 4. Bảng Bản dịch (có hỗ trợ Optimistic Locking qua updated_at cho Phép thử 4)
CREATE TABLE translations (
    id INTEGER NOT NULL DEFAULT nextval('translations_id_seq') PRIMARY KEY,
    string_id INT REFERENCES strings(id) ON DELETE CASCADE,
    locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
    translated_text TEXT,
    status VARCHAR(20) DEFAULT 'draft', -- draft, approved
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE(string_id, locale)
);

CREATE TABLE translation_locks (
    string_id INT NOT NULL REFERENCES strings(id) ON DELETE CASCADE,
    locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    acquired_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    PRIMARY KEY (string_id, locale)
);

CREATE INDEX translation_locks_expiry_idx ON translation_locks (expires_at);

ALTER SEQUENCE users_id_seq OWNED BY users.id;
ALTER SEQUENCE projects_id_seq OWNED BY projects.id;
ALTER SEQUENCE source_versions_id_seq OWNED BY source_versions.id;
ALTER SEQUENCE translations_id_seq OWNED BY translations.id;

CREATE TABLE translation_comments (
    id BIGSERIAL PRIMARY KEY,
    string_id INT NOT NULL REFERENCES strings(id) ON DELETE CASCADE,
    locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    comment TEXT NOT NULL CHECK (length(trim(comment)) BETWEEN 1 AND 5000),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX translation_comments_string_locale_idx
    ON translation_comments (string_id, locale, created_at, id);

CREATE FUNCTION reject_frozen_source_version_mutation() RETURNS trigger AS $$
DECLARE
    version_id INT;
    frozen_time TIMESTAMP WITH TIME ZONE;
BEGIN
    IF TG_TABLE_NAME = 'source_versions' THEN
        IF OLD.frozen_at IS NOT NULL THEN
            IF TG_OP = 'UPDATE'
               AND NEW.project_id IS NOT DISTINCT FROM OLD.project_id
               AND NEW.version_number IS NOT DISTINCT FROM OLD.version_number
               AND NEW.frozen_at IS NOT DISTINCT FROM OLD.frozen_at
               AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
               AND (NEW.frozen_by_user_id IS NOT DISTINCT FROM OLD.frozen_by_user_id
                    OR NEW.frozen_by_user_id IS NULL) THEN
                RETURN NEW;
            END IF;
            RAISE EXCEPTION 'Frozen source versions are immutable';
        END IF;
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    ELSIF TG_TABLE_NAME = 'strings' THEN
        version_id := COALESCE(OLD.source_version_id, NEW.source_version_id);
    ELSE
        SELECT source_version_id INTO version_id FROM strings
        WHERE id = COALESCE(OLD.string_id, NEW.string_id);
    END IF;

    SELECT frozen_at INTO frozen_time FROM source_versions
    WHERE id = version_id FOR SHARE;
    IF frozen_time IS NOT NULL THEN
        IF TG_TABLE_NAME = 'translations' AND TG_OP = 'UPDATE'
           AND NEW.id IS NOT DISTINCT FROM OLD.id
           AND NEW.string_id IS NOT DISTINCT FROM OLD.string_id
           AND NEW.locale IS NOT DISTINCT FROM OLD.locale
           AND NEW.translated_text IS NOT DISTINCT FROM OLD.translated_text
           AND NEW.status IS NOT DISTINCT FROM OLD.status
           AND NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
           AND OLD.user_id IS NOT NULL
           AND NEW.user_id IS NULL THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'Translations in a frozen source version are immutable';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER source_versions_frozen_guard
BEFORE UPDATE OR DELETE ON source_versions
FOR EACH ROW EXECUTE FUNCTION reject_frozen_source_version_mutation();

CREATE TRIGGER strings_frozen_guard
BEFORE INSERT OR UPDATE OR DELETE ON strings
FOR EACH ROW EXECUTE FUNCTION reject_frozen_source_version_mutation();

CREATE TRIGGER translations_frozen_guard
BEFORE INSERT OR UPDATE OR DELETE ON translations
FOR EACH ROW EXECUTE FUNCTION reject_frozen_source_version_mutation();

CREATE TABLE translation_history (
    id BIGSERIAL PRIMARY KEY,
    translation_id INT NOT NULL REFERENCES translations(id) ON DELETE CASCADE,
    old_text TEXT,
    new_text TEXT,
    changed_by_user_id INT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX translation_history_translation_idx
    ON translation_history (translation_id, created_at DESC, id DESC);

CREATE FUNCTION record_translation_text_history() RETURNS trigger AS $$
DECLARE
    actor_id INT;
BEGIN
    actor_id := NULLIF(current_setting('tms.user_id', TRUE), '')::INT;
    IF TG_OP = 'INSERT' THEN
        INSERT INTO translation_history
            (translation_id, old_text, new_text, changed_by_user_id)
        VALUES (NEW.id, NULL, NEW.translated_text, actor_id);
    ELSIF OLD.translated_text IS DISTINCT FROM NEW.translated_text
       OR OLD.status IS DISTINCT FROM NEW.status THEN
        INSERT INTO translation_history
            (translation_id, old_text, new_text, changed_by_user_id)
        VALUES (NEW.id, OLD.translated_text, NEW.translated_text, actor_id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER translations_history_trigger
AFTER INSERT OR UPDATE OF translated_text, status ON translations
FOR EACH ROW EXECUTE FUNCTION record_translation_text_history();

CREATE TABLE notifications (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX notifications_user_unread_idx
    ON notifications (user_id, is_read, created_at DESC);

CREATE TABLE reviews (
    id SERIAL PRIMARY KEY,
    translation_id INT REFERENCES translations(id) ON DELETE CASCADE,
    reviewer_id INT REFERENCES users(id) ON DELETE SET NULL,
    status VARCHAR(20) NOT NULL,
    comment TEXT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE release_snapshots (
    id BIGSERIAL PRIMARY KEY,
    project_id INT NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
    source_version_id INT NOT NULL REFERENCES source_versions(id) ON DELETE RESTRICT,
    locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE RESTRICT,
    version_number VARCHAR(50) NOT NULL,
    json_content TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (source_version_id, locale)
);

CREATE FUNCTION reject_release_snapshot_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Release snapshots are immutable';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER release_snapshots_immutable
BEFORE UPDATE OR DELETE ON release_snapshots
FOR EACH ROW EXECUTE FUNCTION reject_release_snapshot_mutation();

CREATE TABLE release_sync_history (
    id BIGSERIAL PRIMARY KEY,
    project_id INT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    source_version_id INT REFERENCES source_versions(id) ON DELETE SET NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('version_lock', 'api_sync')),
    version_number VARCHAR(50),
    locale VARCHAR(10) REFERENCES locales(code) ON DELETE SET NULL,
    endpoint_url VARCHAR(2048),
    record_count INT NOT NULL DEFAULT 0 CHECK (record_count >= 0),
    new_data JSONB,
    target_rollback_data JSONB,
    requires_auth BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked')),
    actor_user_id INT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP WITH TIME ZONE,
    revoked_by_user_id INT REFERENCES users(id) ON DELETE SET NULL,
    CHECK (
        (type = 'version_lock' AND endpoint_url IS NULL)
        OR (type = 'api_sync' AND endpoint_url IS NOT NULL)
    )
);

CREATE UNIQUE INDEX release_sync_history_version_lock_uidx
    ON release_sync_history (source_version_id)
    WHERE type = 'version_lock' AND source_version_id IS NOT NULL;
CREATE INDEX release_sync_history_project_created_idx
    ON release_sync_history (project_id, created_at DESC, id DESC);

-- 5. Bảng Nhật ký kiểm toán (Audit Trail)
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    project_id INT REFERENCES projects(id) ON DELETE CASCADE,
    string_id INT REFERENCES strings(id) ON DELETE SET NULL,
    actor_user_id INT REFERENCES users(id) ON DELETE SET NULL,
    target_user_id INT REFERENCES users(id) ON DELETE SET NULL,
    action_type VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX audit_logs_action_created_idx
    ON audit_logs (action_type, created_at DESC, id DESC);

CREATE TABLE support_tickets (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title VARCHAR(200) NOT NULL CHECK (length(btrim(title)) BETWEEN 3 AND 200),
    message TEXT NOT NULL CHECK (length(btrim(message)) BETWEEN 10 AND 10000),
    status VARCHAR(10) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'resolved')),
    image_path VARCHAR(500),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX support_tickets_status_created_idx
    ON support_tickets (status, created_at DESC, id DESC);
CREATE INDEX support_tickets_user_created_idx
    ON support_tickets (user_id, created_at DESC, id DESC);

-- Thêm sẵn một dự án mẫu để test
INSERT INTO projects (name, description) VALUES 
('Dự án Quản lý Ứng dụng', 'Hệ thống dịch chuỗi JSON lồng nhau cho ứng dụng web doanh nghiệp');