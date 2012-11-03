#!/usr/bin/env php
<?php
/**
 *******************************************************************************
 * csv2ofx converts a csv file to ofx and qif
 *******************************************************************************
 */

define('PROGRAM', pathinfo(__FILE__, PATHINFO_FILENAME));

if (strpos('@php_bin@', '@php_bin') === 0) { // not a pear install
	define('PROJECT_DIR', dirname(__FILE__).DIRECTORY_SEPARATOR);
} else {
	define(
		'PROJECT_DIR', '@php_dir@'.DIRECTORY_SEPARATOR.PROGRAM.
		DIRECTORY_SEPARATOR
	);
}

define('CUR_DIR', getcwd().DIRECTORY_SEPARATOR);
define('DATE_STAMP', date('Ymd')); // format to yyyymmdd
define('TIME_STAMP', date('Ymd_His')); // format to yyyymmdd_hhmmss
define('XML_FILE', PROJECT_DIR.PROGRAM.'.xml');

require PROJECT_DIR.'Autoload.php';

$defType = array('ofx' => 'CHECKING', 'qif' => 'Bank');
$ofxList = array(
	'CHECKING' => array('checking'),
	'SAVINGS' => array('savings'),
	'MONEYMRKT' => array('market'),
	'CREDITLINE' => array('visa', 'master', 'express', 'discover')
);

$bankList = array(
	'checking', 'savings', 'market', 'receivable', 'payable', 'visa', 'master',
	'express', 'discover'
);

$qifList = array('Bank' => $bankList, 'Cash' => array('cash'));
$typeList = array('ofx' => $ofxList, 'qif' => $qifList);
$ext = array('ofx' => 'ofx', 'qif' => 'qif');

// create the parser from xml file
$parser = Console_CommandLine::fromXmlFile(XML_FILE);

try {
	// run the parser
	$result = $parser->parse();

	// command arguments
	$source = $result->args['source'];
	$dest = $result->args['dest'];

	// load options if present
	$delimiter = $result->options['delimiter'];
	$mapping = $result->options['mapping'];
	$primary = $result->options['primary'];
	$start = date('YmdHis', strtotime($result->options['start']));
	$end = date('YmdHis', strtotime($result->options['end']));
	$collAccts = explode(',', $result->options['collapse']);
	$currency = $result->options['currency'];
	$language = $result->options['language'];
	$overwrite = $result->options['overwrite'];
	$transfer = $result->options['transfer'];
	$qif = $result->options['qif'];
	$type = $qif ? 'qif' : 'ofx';
	$defType = $result->options['accountType'] ?: $defType[$type];
	$typeList = $typeList[$type];
	$ext = $ext[$type];

	switch ($dest){
		case '$':
			$stdout = TRUE;
			break;

		case '':
			$stdout = FALSE;
			$dest = CUR_DIR.TIME_STAMP.'_'.$mapping.'.'.$ext;
			break;

		default:
			$stdout = FALSE;
	} //<-- end switch -->

	if ($result->options['debug']) {
		print('[Command opts] ');
		print_r($result->options);
		print('[Command args] ');
		print_r($result->args);
		exit(0);
	} //<-- end if -->

	// program setting
	$vars = new vars($result->options['verbose']);
	$file = new file($result->options['verbose']);
	$array = new myarray($result->options['verbose']);
	$string = new string($result->options['verbose']);

	// execute program
	if (file_exists($source)) {
		$content = $file->file2String($source);
		$content = $string->makeLFLineEndings($content, $delimiter);
	} else {
		$content = $source;
	} //<-- end if -->

	$content = $string->lines2Array($content);
	$csvContent = $string->csv2Array($content, $delimiter);
	$csvContent = $array->arrayTrim($csvContent, $delimiter);
	$csvContent = $array->arrayLengthen($csvContent, $delimiter);
	$csvContent = $array->arrayInsertKey($csvContent);
	array_shift($csvContent);

	$csv2ofx = new csv2ofx($mapping, $csvContent, $result->options['verbose']);
	$csv2ofx->csvContent = $csv2ofx->cleanAmounts();
	$splitContent = $csv2ofx->makeSplits();

	if ($csv2ofx->split) {
		// verify splits
		$csv2ofx->verifySplits($splitContent);

		// sort splits by account name
		$function = array('myarray', 'arraySortBySubValue');
		$field = array_fill(0, count($splitContent), $csv2ofx->headAccount);
		$splitContent = array_map($function, $splitContent, $field);

		// combine splits of collapsable accounts
		$splitContent = $csv2ofx->collapseSplits($splitContent, $collAccts);

		// get accounts and keys
		$maxAmounts = $csv2ofx->getMaxSplitAmounts($splitContent);
		$accounts = $csv2ofx->getAccounts($splitContent, $maxAmounts);
		$keys = array_keys($accounts);

		// move main splits to beginning of transaction array
		$function = array('myarray', 'arrayMove');
		$field = array_fill(0, count($splitContent), $keys);
		$splitContent = array_map($function, $splitContent, $field);
	} else { // not a split transaction
		$accounts = $csv2ofx->getAccounts($splitContent);
	} //<-- end if split -->

	$accountTypes = $csv2ofx->getAccountTypes($accounts, $typeList, $defType);
	$accounts = array_combine($accounts, $accountTypes);
	$uniqueAccounts = array_unique(sort($accounts));

	// variable mode setting
	if ($result->options['variables']) {
		print_r($vars->getVars(get_defined_vars()));
		exit(0);
	}

	if ($result->options['qif']) {
		$content = '';

		foreach ($csv2ofx->accounts as $account => $accountType) {
			$content .=
				$csv2ofx->getQIFTransactionHeader($account, $accountType);

			// loop through each transaction
			foreach ($csv2ofx->newContent as $transaction) {
				// find the rows matching the account name
				if ($transaction[0][$csv2ofx->headAccount] == $account) {
					if (!$csv2ofx->split) {
						if ($csv2ofx->headSplitAccount) {
							$tranSplitAccount =
								$transaction[0][$csv2ofx->headSplitAccount];
						} else {
							$tranSplitAccount = $csv2ofx->defSplitAccount;
						}

						// if this is a transfer from the primary account,
						// skip it and go to the next transaction
						if ($tranSplitAccount == $primary) {
							continue;
						}
					} //<-- end if not split -->

					$tranDate = strtotime($transaction[0][$csv2ofx->headDate]);

					// if transaction is not in the specified date range,
					// go to the next one
					if ($tranDate <= $startDate || $tranDate >= $endDate) {
						continue;
					}

					// get data for first split
					$csv2ofx->setTransactionData($transaction[0], $tranDate);

					$content .=
						$csv2ofx->getQIFTransactionContent($accountType);

					if ($csv2ofx->split) {
						// loop through each additional split
						foreach ($transaction as $key => $split) {
							if ($key > 0) {
								$csv2ofx->setTransactionData($split,
									$tranDate
								);

								$content .= $csv2ofx->getQIFSplitContent();
							} //<-- end if -->
						} //<-- end loop through splits -->
					} else { // not a split transaction
						$content .= $csv2ofx->getQIFSplitContent();
					} //<-- end if split -->

					$content .= $csv2ofx->getQIFTransactionFooter();
				} //<-- end if correct account -->
			} //<-- end loop through transactions -->
		} //<-- end loop through accounts -->
	} else { // it's ofx
		// remove non xml compliant characters
		$csvContent = $array->xmlize($csvContent);

		if ($result->options['transfer']) {
			$content = $csv2ofx->getOFXTransferHeader($tranDate);
		} else { // it's a transaction
			$content = $csv2ofx->getOFXTransactionHeader($tranDate);
		} //<-- end if transfer -->

		// loop through each account
		foreach ($csv2ofx->accounts as $account => $accountType) {
			// create account id using an md5 hash of the account name
			$accountId = md5($account);

			if (!$result->options['transfer']) {
				$content .= $csv2ofx->getOFXTransactionAccountStart();
			} //<-- end if not transfer -->

			// find the rows matching the account name and loop
			// through each transaction
			foreach ($csvContent as $transaction) {
				if ($transaction[$csv2ofx->headAccount] == $account) {
					// if this is a transfer from the primary account,
					// skip it and go to the next transaction
					$tranSplitAccount =
						$transaction[$csv2ofx->headSplitAccount];

					if ($tranSplitAccount == $primary) {
						continue;
					}

					// else, business as usual
					$tranDate = strtotime($transaction[$csv2ofx->headDate]);

					// if transaction is not in the specified date range,
					// go to the next one
					if ($tranDate <= $startDate || $tranDate >= $endDate) {
						continue;
					}

					$csv2ofx->setTransactionData($transaction, $tranDate);

					if ($result->options['transfer']) {
						$content .=
							$csv2ofx->getOFXTransfer($account, $accountType);
					} else { // it's a transaction
						$content .= $csv2ofx->getOFXTransaction();
					} //<-- end if transfer -->
				} //<-- end if -->
			} //<-- end for loop through transactions -->

			if (!$result->options['transfer']) {
				$content .= $csv2ofx->getOFXTransactionAccountEnd();
			} //<-- end if not transfer -->
		} //<-- end foreach loop through accounts -->

		if ($result->options['transfer']) {
			$content .=$csv2ofx->getOFXTransferFooter();
		} else { // it's a transaction
			$content .=	$csv2ofx->getOFXTransactionFooter();
		} //<-- end if transfer -->
	} //<-- end if qif -->

	if ($stdout) {
		print($content);
	} else {
		$file->write2file($content, $dest, $result->options['overwrite']);
	} //<-- end if not test mode -->

	exit(0);
} catch (Exception $e) {
	fwrite(STDOUT, 'Program '.PROGRAM.': '.$e->getMessage()."\n");
	exit(1);
}
?>
