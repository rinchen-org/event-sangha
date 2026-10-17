<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);

function convert_from_utc0(DateTime $datetime): DateTime {
  $datetime_original = new Datetime(
    $datetime->format('Y-m-d H:i:s'),
    new DateTimeZone('UTC')
  );

  $datetime_original->setTimezone(new DateTimeZone('America/La_Paz'));
  return $datetime_original;
}

function convert_to_utc0(DateTime $datetime): DateTime {
  $datetime_original = new Datetime(
    $datetime->format('Y-m-d H:i:s'),
    new DateTimeZone('America/La_Paz')
  );

  $datetime_original->setTimezone(new DateTimeZone('UTC'));

  return $datetime_original;
}

?>
