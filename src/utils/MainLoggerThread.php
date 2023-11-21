<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\utils;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use function date;
use function fclose;
use function file_exists;
use function fopen;
use function fstat;
use function fwrite;
use function gzopen;
use function is_dir;
use function is_file;
use function is_resource;
use function mkdir;
use function stream_copy_to_stream;
use function strlen;
use function touch;
use function unlink;

final class MainLoggerThread extends Thread{
	private const MAX_FILE_SIZE = 32 * 1024 * 1024; //32 MB

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $buffer;
	private bool $syncFlush = false;
	private bool $shutdown = false;

	public function __construct(
		private string $logFile,
		private string $archiveDir
	){
		$this->buffer = new ThreadSafeArray();
		touch($this->logFile);
		if(!@mkdir($this->archiveDir) && !is_dir($this->archiveDir)){
			throw new \RuntimeException("Unable to create archive directory: " . (
				is_file($this->archiveDir) ? "it already exists and is not a directory" : "permission denied"));
		}
	}

	public function write(string $line) : void{
		$this->synchronized(function() use ($line) : void{
			$this->buffer[] = $line;
			$this->notify();
		});
	}

	public function syncFlushBuffer() : void{
		$this->synchronized(function() : void{
			$this->syncFlush = true;
			$this->notify(); //write immediately
		});
		$this->synchronized(function() : void{
			while($this->syncFlush){
				$this->wait(); //block until it's all been written to disk
			}
		});
	}

	public function shutdown() : void{
		$this->synchronized(function() : void{
			$this->shutdown = true;
			$this->notify();
		});
		$this->join();
	}

	/**
	 * @param resource $logResource
	 */
	private function writeLogStream($logResource, int &$offset) : bool{
		while(($chunk = $this->buffer->shift()) !== null){
			fwrite($logResource, $chunk);
			$offset += strlen($chunk);
			if($offset >= self::MAX_FILE_SIZE){
				return false;
			}
		}

		$this->synchronized(function() : void{
			if($this->syncFlush){
				$this->syncFlush = false;
				$this->notify(); //if this was due to a sync flush, tell the caller to stop waiting
			}
		});
		return true;
	}

	/** @return resource */
	private function openLogFile(string $file, int &$size){
		$logResource = fopen($file, "ab");
		if(!is_resource($logResource)){
			throw new \RuntimeException("Couldn't open log file");
		}
		$stat = fstat($logResource);
		if($stat === false) throw new AssumptionFailedError("ftell() should not fail here");
		$size = $stat['size'];
		return $logResource;
	}

	private function compressLogFile() : void{
		$i = 0;
		$date = date("Y-m-d\TH.i.s");
		do{
			//this shouldn't be necessary, but in case the user messes with the system time for some reason ...
			$out = $this->archiveDir . "/server.{$date}_$i.log.gz";
			$i++;
		}while(file_exists($out));

		$logFile = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => fopen($this->logFile, 'rb'));
		$archiveFile = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => gzopen($out, 'wb'));

		//TODO: the disk could run out of space during this operation
		//we have no log context here so I'm not sure what to do about it
		ErrorToExceptionHandler::trapAndRemoveFalse(fn() => stream_copy_to_stream($logFile, $archiveFile));

		fclose($logFile);
		fclose($archiveFile);
		@unlink($this->logFile);
	}

	public function run() : void{
		$size = 0;
		$logResource = $this->openLogFile($this->logFile, $size);

		while(!$this->shutdown){
			while(!$this->writeLogStream($logResource, $size)){
				fclose($logResource);
				$this->compressLogFile();
				$logResource = $this->openLogFile($this->logFile, $size);
			}
			$this->synchronized(function() : void{
				if(!$this->shutdown && !$this->syncFlush){
					$this->wait();
				}
			});
		}

		$this->writeLogStream($logResource, $size);

		fclose($logResource);
	}
}
