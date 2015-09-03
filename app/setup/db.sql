CREATE TABLE `password_requests` (
	`token`	TEXT NOT NULL UNIQUE,
	`created`	INTEGER NOT NULL,
	`email`	TEXT NOT NULL,
	`uid`	TEXT NOT NULL,
	PRIMARY KEY(token)
);
