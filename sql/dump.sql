CREATE TABLE `search_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `searchId` int(10) UNSIGNED NOT NULL,
  `lotUrl` varchar(255) NOT NULL,
  `dateEnd` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `search_scheduler` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `params` text NOT NULL,
  `status` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `lastExecution` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `notified` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `lastResults` longtext,
  `lastProcessedCount` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `search_history`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `search_scheduler`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `search_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `search_scheduler`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
