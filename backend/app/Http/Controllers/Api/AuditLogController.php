<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = AuditLog::query()->with('user')->latest();

        if (! $user->isRole('super_admin', 'admin')) {
            $query->where('user_id', $user->id);
        } elseif ($request->integer('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $query->when($request->string('action')->toString(), fn ($q, $action) => $q->where('action', $action))
            ->when($request->string('from')->toString(), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->string('to')->toString(), fn ($q, $to) => $q->whereDate('created_at', '<=', $to));

        return AuditLogResource::collection($query->paginate($request->integer('per_page', 25)));
    }
}
