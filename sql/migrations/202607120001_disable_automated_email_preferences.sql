UPDATE user_notification_preferences
SET email_enabled = 0;

ALTER TABLE user_notification_preferences
  MODIFY email_enabled TINYINT(1) NOT NULL DEFAULT 0;
