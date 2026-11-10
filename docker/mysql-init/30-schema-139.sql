-- mysql.sql records schema_version 133. Kanboard 1.2.45 ships VERSION 139.
USE kanboard;

ALTER TABLE `projects` ADD COLUMN `per_swimlane_task_limits` INT DEFAULT 0 NOT NULL;
ALTER TABLE `projects` ADD COLUMN `task_limit` INT DEFAULT 0;
ALTER TABLE `swimlanes` ADD COLUMN `task_limit` INT DEFAULT 0;
ALTER TABLE `projects` ADD COLUMN `enable_global_tags` TINYINT(1) DEFAULT 1 NOT NULL;
ALTER TABLE `users` ADD COLUMN `theme` VARCHAR(50) DEFAULT 'light' NOT NULL;
ALTER TABLE `comments` ADD COLUMN `visibility` VARCHAR(25) NOT NULL DEFAULT 'app-user';

DELETE FROM schema_version;
INSERT INTO schema_version VALUES (139);
