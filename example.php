<?php
	/**
	 * For the purpose of this example we accept that our server class is initialized in a file located on http://example.com/services/tunnel.php  
	 */
	include('vibe-cli.php');
	$cli = new vibe_tunnel_cli('example.com', '/services/tunnel.php', 'localhost', 'user', 'pass', 'mydb');
	$cli->query("select id,name from testtab", 'VIBE-ASSOC');
	$cli->query("insert into testtab set id=null, name='something'");
	$cli->exec();
	$array = $cli->fetch_assoc();
	foreach ($array as $row) {
		echo $row['id'].': '.$row['name'].'<br/>'; // list every row on a separate line;
	}
	$cli->next_transaction();
	if ($cli->get_transaction_error() == '') echo 'One row inserted with id:'.$cli->insert_id();
?>