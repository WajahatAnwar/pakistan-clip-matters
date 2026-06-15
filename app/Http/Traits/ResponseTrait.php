<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Auth;

trait ResponseTrait
{
    /**
     * Success response
     */
    public function successResponse($data = null, $message = 'Success', $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * Error response
     */
    public function errorResponse($message = 'Error', $code = 400, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Not found response
     */
    public function notFoundResponse($message = 'Resource not found')
    {
        return $this->errorResponse($message, 404);
    }

    /**
     * Validation error response
     */
    public function validationErrorResponse($errors, $message = 'Validation failed')
    {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Inertia render response
     */
    public function inertiaResponse($component, $props = [])
    {
        if (Auth::user()->hasAnyRole(['superAdmin', 'admin', 'manager'])) {
            return inertia('AdminSide/' . $component, $props);
        } else {
            return inertia('UserSide/' . $component, $props);
        }
    }

    /**
     * Inertia render response with success message
     */
    public function inertiaSuccessResponse($component, $props = [], $message = 'Success')
    {
        if (Auth::user()->hasAnyRole(['superAdmin', 'admin', 'manager'])) {
            $component = 'AdminSide/' . $component;
        } else {
            $component = 'UserSide/' . $component;
        }

        return inertia($component, array_merge($props, [
            'flash' => [
                'success' => true,
                'message' => $message
            ]
        ]));
    }

    /**
     * Inertia render response with error message
     */
    public function inertiaErrorResponse($component, $props = [], $message = 'Error')
    {
        $user = auth()->user();
        if ($user && $user->hasAnyRole(['superAdmin', 'admin', 'manager'])) {
            $component = 'AdminSide/' . $component;
        } else {
            $component = 'UserSide/' . $component;
        }
        return inertia($component, array_merge($props, [
            'flash' => [
                'success' => false,
                'message' => $message
            ]
        ]));
    }
}
