<?php

namespace App\Enums;

enum UploadSessionStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
