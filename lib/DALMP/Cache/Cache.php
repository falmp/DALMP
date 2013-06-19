<?php
namespace DALMP\Cache;

/**
 * CacheInterface - interface class
 *
 * @author Nicolas de Bari Embriz <nbari@dalmp.com>
 * @package DALMP
 * @license BSD License
 * @version 3.0
 */
interface CacheInterface {

  /**
   * Constructor
   *
   * @param string $host
   * @param int $port
   * @param int $timeout
   * @param bool $compress
   */
  public function __construct($host, $port, $timeout=1, $compress=False);

  /**
   * Store data at the server
   *
   * @param string $key
   * @param string $value
   * @param int $expire time in seconds(default is 0 meaning unlimited)
   */
  public function Set($key, $value, $expire = 0);

  /**
   * Retrieve item from the server
   *
   * @param string $key
   */
  public function Get($key);

  /*
   * Delete item from the server
   *
   * @param string $key
   */
  public function Delete($key);

  /**
   * Flush cache
   */
  public function Flush();

  /**
   * Get cache stats
   */
  public function getStats();

  /**
   * X execute/call custom methods
   *
   * @return cache object
   */
  public function X();

}
