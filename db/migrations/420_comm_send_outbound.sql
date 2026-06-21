-- 420: add send_status / send_error / sent_by_user_id to comm_messages
--      extend source ENUM to include 'sent' for maltyweb-originated outbound replies

ALTER TABLE comm_messages
  MODIFY COLUMN source ENUM('gmail','manual','sent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gmail';

ALTER TABLE comm_messages
  ADD COLUMN send_status ENUM('sent','failed') COLLATE utf8mb4_unicode_ci NULL AFTER source,
  ADD COLUMN send_error VARCHAR(512) COLLATE utf8mb4_unicode_ci NULL AFTER send_status,
  ADD COLUMN sent_by_user_id INT UNSIGNED NULL AFTER created_by_user_id,
  ADD CONSTRAINT fk_comm_messages_sentby FOREIGN KEY (sent_by_user_id) REFERENCES users(id);
