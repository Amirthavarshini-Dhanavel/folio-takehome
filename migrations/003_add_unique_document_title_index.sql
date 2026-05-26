CREATE UNIQUE INDEX idx_documents_title_nocase ON documents(title COLLATE NOCASE);
