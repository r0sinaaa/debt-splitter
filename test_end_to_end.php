<?php
require 'balances.php';
require 'algorithm.php';

$balances = getGroupBalances(1);
echo "Balances:\n";
print_r($balances);

$transactions = greedySimplify($balances);
echo "Settlement:\n";
print_r($transactions);