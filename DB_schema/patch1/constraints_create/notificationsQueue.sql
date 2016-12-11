ALTER TABLE `notificationsQueue`
	ADD
		CONSTRAINT `notificationsQueue_ibfk_1`
		FOREIGN KEY (`series_id`)
		REFERENCES `series` (`id`),
	ADD
		CONSTRAINT `notificationsQueue_ibfk_2`
		FOREIGN KEY (`user_id`)
		REFERENCES `users` (`id`)
		ON DELETE CASCADE
		ON UPDATE CASCADE;

