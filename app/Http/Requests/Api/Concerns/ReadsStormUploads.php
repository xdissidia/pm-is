<?php

namespace App\Http\Requests\Api\Concerns;

use Illuminate\Http\UploadedFile;

trait ReadsStormUploads
{
    /**
     * The uploaded files, keyed the way they were sent.
     *
     * @return array<int|string, UploadedFile>
     */
    public function uploads(): array
    {
        return $this->file('uploads', []);
    }

    /**
     * STORM names its upload parts `uploads[{attachment id}]`, so the keys are
     * its own ids for the files — worth keeping, since they are what PMIS
     * sends back to drop a file off the ticket again.
     *
     * A plain `uploads[]` list arrives keyed 0,1,2… and means nothing of the
     * sort, so a run starting at zero is treated as an ordinary list.
     *
     * @return array<int|string, int> upload key => STORM attachment id
     */
    public function uploadStormIds(): array
    {
        $keys = array_keys($this->uploads());

        if (empty($keys) || $keys === range(0, count($keys) - 1)) {
            return [];
        }

        $ids = [];

        foreach ($keys as $key) {
            if (is_numeric($key) && (int) $key > 0) {
                $ids[$key] = (int) $key;
            }
        }

        return $ids;
    }
}
