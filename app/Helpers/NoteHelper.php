<?php

namespace App\Helpers;

class NoteHelper
{

    private array $required_keys = ['id', 'title', 'last_modified', 'text', 'protected', 'auto_wipe'];

    public function validateJsonString(string $json_string): bool
    {

        $notes = json_decode($json_string, true);

        if(!is_array($notes)) {
            return false;
        }

        // iterate through each note, to make sure, the data-model is correct.
        foreach($notes as $note) {
            $keys = array_keys($note);
            $key_differences = array_diff_key($this->required_keys, $keys);

            // not all required keys are in the note. Some data is missing.
            if(!empty($key_differences)) {
                return false;
            }

        }

        return true;

    }

}
