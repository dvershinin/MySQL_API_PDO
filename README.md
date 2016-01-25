# MySQL Migration to PDO Compatibility Pack
This little library will define all mysql_* function for use with PDO (some mysql_* functions will return nothing, read the source code to find out which of those will).

The little class provided will handle the PDO connection and queries. This library is **only** for testing purpose. **Do not** use this library in production. Migrate directly to PDO and use Prepared Statements with binding parameters (mysql_* functions are deprecated and are removed in versions as of PHP 7).

I will not give any guarantee that this compatibility pack will handle all queries without any issues. There may be some complex query which will produce issues. If they arise, you should maybe just migrate to PDO instead of putting up with this library, as this library is **only** for testing purpose.

Usage
=====
1. Download the mysql_combat.php and copy it into your script's root directory (or any other directory, as long as you update the path to the library).

2. (OPTIONAL) Define `DB_CONNECT_CHARSET` *before* including the library to define a different charset to use other than utf8mb4 (the true utf8 charset in MySQL).

3. (OPTIONAL) This library will emulate Prepared Statements with binding parameters, which is why this library *does not* escape values*. If you don't want this library to emulate that or you get issue with some queries, you have two options now:
  * Define `NO_PREPARE` before including the library to stop emulating Prepared Statements globally or
  
  * Use `MySQL_API_PDO()::app()->noPrepare(true);` before making troublesome queries to stop emulating. You can use `MySQL_API_PDO()::app()->noPrepare(false);` to start emulating again.

4. Include the library in your php script
`include_once('mysql_combat.php');`

5. Good Luck and God speed

* Without defining `NO_PREPARE` values don't get escaped as it will be unnecessary due to Prepared Statements. Defining `NO_PREPARE` will disable emulation of Prepared Statements and make `mysql_(real_)escape_string` add slashes with `add_slashes`. Please note that use of addslashes() can be cause of security issues on most databases. Which is also why this library is only for testing purpose.
