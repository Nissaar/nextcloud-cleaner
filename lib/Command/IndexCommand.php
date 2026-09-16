<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Command;

use OCA\PhotoCleaner\Service\IndexService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ photocleaner:index <user>` — build or refresh a user's month index.
 *
 * The web UI can start a scan too, but a first pass over a very large library is
 * better run here: there is no request timeout to fit inside and the progress is
 * visible.
 */
class IndexCommand extends Command {

	public function __construct(
		private IndexService $indexService,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('photocleaner:index')
			->setDescription('Build or refresh the Photo Cleaner month index for a user')
			->addArgument('user', InputArgument::REQUIRED, 'The user whose library to index')
			->addOption('full', null, InputOption::VALUE_NONE, 'Discard the existing index and read everything again')
			->addOption(
				'until-complete',
				null,
				InputOption::VALUE_NONE,
				'Keep going until the whole library is indexed, instead of stopping after one run',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if (!$this->userManager->userExists($userId)) {
			$output->writeln('<error>No such user: ' . $userId . '</error>');
			return 1;
		}

		$full = (bool)$input->getOption('full');
		$untilComplete = (bool)$input->getOption('until-complete');

		$onProgress = static function (int $found, int $batch) use ($output): void {
			$output->write("\r  indexed " . $found . ' items');
		};

		$passes = 0;
		do {
			$scan = $this->indexService->scan($userId, $full && $passes === 0, $onProgress);
			$passes++;

			if ($scan->getError() !== null) {
				$output->writeln('');
				$output->writeln('<error>' . $scan->getError() . '</error>');
				return 1;
			}
			// A run stops after a fixed number of batches so cron stays responsive;
			// on the command line there is no such constraint, so just go again.
		} while ($untilComplete && !$scan->getComplete() && $passes < 500);

		$output->writeln('');
		$output->writeln(sprintf(
			'<info>%s</info> %d items indexed, %s',
			$userId,
			$scan->getFound(),
			$scan->getComplete() ? 'index complete' : 'more to do — run again or pass --until-complete',
		));

		return 0;
	}
}
