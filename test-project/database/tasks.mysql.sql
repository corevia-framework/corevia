-- Run this in your chosen MySQL database when MySQL becomes available.
CREATE TABLE learning_tasks (
    id VARCHAR(21) PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    completed TINYINT(1) NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
