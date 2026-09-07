ALTER TABLE analysis_requests ADD analysis_date CHAR(8) NULL,
    ADD KEY idx_analysis_requests_date (installation_id, analysis_date);
