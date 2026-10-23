-- Xóa bảng cũ nếu tồn tại để làm sạch
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS reviews CASCADE;
DROP TABLE IF EXISTS release_snapshots CASCADE;
DROP TABLE IF EXISTS translations CASCADE;
DROP TABLE IF EXISTS strings CASCADE;
DROP TABLE IF EXISTS source_versions CASCADE;
DROP TABLE IF EXISTS locales CASCADE;
DROP TABLE IF EXISTS project_members CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS projects CASCADE;

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'translator',
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX users_username_lower_uidx ON users (lower(username));
CREATE UNIQUE INDEX users_email_lower_uidx ON users (lower(email));

-- 1. Bảng Dự án
CREATE TABLE projects (
    id SERIAL PRIMARY KEY,
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
    id SERIAL PRIMARY KEY,
    project_id INT REFERENCES projects(id) ON DELETE CASCADE,
    version_number VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

-- Supported translation locales
CREATE TABLE locales (
    code VARCHAR(10) PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

INSERT INTO locales (code, name) VALUES
('vi', 'Tiếng Việt'),
('en', 'English'),
('ja', 'Japanese');

-- 4. Bảng Bản dịch (có hỗ trợ Optimistic Locking qua updated_at cho Phép thử 4)
CREATE TABLE translations (
    id SERIAL PRIMARY KEY,
    string_id INT REFERENCES strings(id) ON DELETE CASCADE,
    locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
    translated_text TEXT,
    status VARCHAR(20) DEFAULT 'draft', -- draft, approved
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE(string_id, locale)
);

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
    ELSIF OLD.translated_text IS DISTINCT FROM NEW.translated_text THEN
        INSERT INTO translation_history
            (translation_id, old_text, new_text, changed_by_user_id)
        VALUES (NEW.id, OLD.translated_text, NEW.translated_text, actor_id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER translations_history_trigger
AFTER INSERT OR UPDATE OF translated_text ON translations
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

-- 5. Bảng Nhật ký kiểm toán (Audit Trail)
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    project_id INT REFERENCES projects(id) ON DELETE CASCADE,
    string_id INT REFERENCES strings(id) ON DELETE SET NULL,
    action_type VARCHAR(50) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Thêm sẵn một dự án mẫu để test
INSERT INTO projects (name, description) VALUES 
('Dự án Quản lý Ứng dụng', 'Hệ thống dịch chuỗi JSON lồng nhau cho ứng dụng web doanh nghiệp');