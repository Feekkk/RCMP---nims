ALTER TABLE `nexcheck_request`
  ADD COLUMN `rejected_at` TIMESTAMP NULL DEFAULT NULL AFTER `terms_accepted_at`,
  ADD COLUMN `rejected_by` VARCHAR(32) NULL DEFAULT NULL AFTER `rejected_at`,
  ADD COLUMN `rejection_reason` VARCHAR(512) NULL DEFAULT NULL AFTER `rejected_by`,
  ADD INDEX `idx_nexcheck_rejected_at` (`rejected_at`),
  ADD CONSTRAINT `fk_nexcheck_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users`(`staff_id`) ON DELETE SET NULL;
