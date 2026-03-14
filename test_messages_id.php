<?php

$head = [
    'message_id' => '  <0123.abcd@domain.com>  ',
    'threadindex' => '  ThReAdIdX_Hx1  ',
    'references' => ' <ref1@domain.com> <ref2@domain.com> '
];

// O que ocorre no Pre Item Add (o que é salvo no banco primeiro temp ticket 0):
$messageIdPreItemAdd = trim(html_entity_decode($head['message_id'] ?? ''));
echo "PRE_ITEM_ADD Saves exactly:\n";
var_dump($messageIdPreItemAdd);


// O que ocorre no Item Add ($result = DB->update)
function getMailReferences(string $threadindex, string $references): array
{
    $messages_id = [];
    if (!empty($threadindex)) { $messages_id[] = $threadindex; }
    if (!empty($references)) {
        if (preg_match_all('/<.*?>/', $references, $matches)) {
            $messages_id = array_merge($messages_id, $matches[0]);
        }
    }
    return array_filter($messages_id, function (string $val): bool {
        return trim($val) !== '';
    });
}

$messages_id_array = getMailReferences(
    $head['threadindex'] ?? '',
    html_entity_decode($head['references'] ?? '')
);
$messages_id_array[] = trim(html_entity_decode($head['message_id'] ?? ''));


echo "\nITEM_ADD uses this array for WHERE message_id IN (... array ...):\n";
var_dump($messages_id_array);

echo "\nDoes the PRE_ITEM_ADD string exist safely inside the ITEM_ADD array?\n";
var_dump(in_array($messageIdPreItemAdd, $messages_id_array, true));
