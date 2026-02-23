<?php

namespace App\Services;

class OcrStateAccessor
{
    public function firstName(array $state): array
    {
        return [
            'location_raw' => $this->firstNonEmpty($state, ['fp_firstname_location']),
            'ocr_guess' => $this->firstNonEmpty($state, ['fp_firstname_ocr', 'firstname_ocr']),
            'ocr_score' => $this->firstNonEmpty($state, ['fp_firstname_ocr_score', 'fp_firstname_score', 'firstname_score']),
            'ocr_options_raw' => $this->firstNonEmpty($state, ['fp_firstname_ocr_options']),
            'human_value' => $this->firstNonEmpty($state, ['fp_firstname_human', 'firstname_human']),
        ];
    }

    public function lastName(array $state): array
    {
        return [
            'location_raw' => $this->firstNonEmpty($state, ['fp_lastname_location']),
            'ocr_guess' => $this->firstNonEmpty($state, ['fp_lastname_ocr', 'lastname_ocr']),
            'ocr_score' => $this->firstNonEmpty($state, ['fp_lastname_ocr_score', 'fp_lastname_score', 'lastname_score']),
            'ocr_options_raw' => $this->firstNonEmpty($state, ['fp_lastname_ocr_options']),
            'human_value' => $this->firstNonEmpty($state, ['fp_lastname_human', 'lastname_human']),
        ];
    }

    public function dob(array $state): array
    {
        return [
            'location_raw' => $this->firstNonEmpty($state, ['fp_dob_location', 'dob_location']),
            'ocr_guess' => $this->firstNonEmpty($state, ['fp_dob_ocr', 'dob_ocr']),
            'ocr_score' => $this->firstNonEmpty($state, ['fp_dob_ocr_score', 'fp_dob_score', 'dob_score']),
            'ocr_options_raw' => $this->firstNonEmpty($state, ['fp_dob_ocr_options', 'dob_ocr_options']),
            'human_value' => $this->firstNonEmpty($state, ['fp_dob_human', 'dob_human']),
        ];
    }

    private function firstNonEmpty(array $state, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $state) && $state[$candidate] !== null && $state[$candidate] !== '') {
                return $state[$candidate];
            }
        }

        return null;
    }
}
