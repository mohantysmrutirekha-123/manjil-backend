<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;



class AuthController extends Controller
{
    // Register
    public function register(Request $req)
    {
        try {

            //Validation
            $validator = Validator::make($req->all(), [
                'name'     => 'required|string|max:100',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|min:6',
                'role'     => 'sometimes|in:user,customer,vendor'
            ], [
                'name.required'     => 'Name is required',

                'email.required'    => 'Email is required',
                'email.email'       => 'Please enter a valid email address',
                'email.unique'      => 'Email already exists, please use another email id',

                'password.required' => 'Password is required',
                'password.min'      => 'Password must be at least 6 characters'
            ]);

            // Validation failed
            if ($validator->fails()) {
                return response()->json([
                    'status'  => 422,
                    'message' => $validator->errors()->first()
                ], 422);
            }

            // Create user
            $user = User::create([
                'name'     => $req->name,
                'email'    => $req->email,
                'password' => bcrypt($req->password),
                'role'     => $req->role ?? 'user'
            ]);

             // Audit Log
                $userData = $user->toArray();
                unset($userData['password']);

                saveLog('USER_REGISTER', 'User', $userData);

            return response()->json([
                'status'  => 200,
                'message' => 'User registered successfully',
                'data'    => $user
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

     // Login
    public function login(Request $req)
    {
        $req->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (!Auth::attempt($req->only('email','password'))) {

            $user = User::where('email', $req->email)->first();

            saveLog('LOGIN_FAILED', 'User', [
                'email' => $req->email,
                'reason' => 'Invalid credentials',
                // 'ip' => $req->ip(),
                // 'time' => now()
            ], $user ? $user->id : null);

            return response()->json([
                'status'  => 401,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('api')->plainTextToken;

        // Audit Log
        $userData = $user->toArray();
        unset($userData['password']);

        saveLog('USER_LOGIN', 'User', $userData);

        return response()->json([
            'status'  => 200,
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => $user
        ]);
    }

    // User List
    public function userList(Request $request)
    {
        $user = auth()->user();

        $query = DB::table('users');

        // Role-based
        // if ($user->role != 'admin') {
        //     $query->where('id', $user->id);
        // }

        //Search
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%$search%")
                  ->orWhere('email', 'LIKE', "%$search%")
                  ->orWhere('role', 'LIKE', "%$search%");
            });
        }

        // Role Type Filter
        if ($request->filled('type')) {
            $query->where('role', $request->type); 
        }

        // Pagination
        $perPage = $request->per_page ?? 10;

        $users = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 200,
            'message' => 'User list fetched successfully',
            'data' => $users
        ]);
    }

     // Update User
    public function updateUser(Request $req, $id)
    {
        try {
            $authUser = auth()->user();

            // Only admin or same user can update
            if ($authUser->role !== 'admin' && $authUser->id != $id) {
                return response()->json([
                    'status' => 403,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 404,
                    'message' => 'User not found'
                ], 404);
            }

            // Validation
            $validator = Validator::make($req->all(), [
                'name'     => 'sometimes|string|max:100',
                'email'    => 'sometimes|email|unique:users,email,' . $id,
                'password' => 'nullable|min:6',
                'role'     => 'sometimes|in:user,customer,admin'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => $validator->errors()->first()
                ], 422);
            }

            // Update data
            if ($req->filled('name')) {
                $user->name = $req->name;
            }

            if ($req->filled('email')) {
                $user->email = $req->email;
            }

            if ($req->filled('password')) {
                $user->password = bcrypt($req->password);
            }

            if ($req->filled('role')) {
                $user->role = $req->role;
            }

            $user->save();

            // Audit log
            saveLog('USER_UPDATE', 'User', $user->toArray());

            return response()->json([
                'status' => 200,
                'message' => 'User updated successfully',
                'data' => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Detele user
    public function deleteUser($id)
    {
        try {
            $authUser = auth()->user();

            // Only admin can delete
            if ($authUser->role !== 'admin') {
                return response()->json([
                    'status' => 403,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 404,
                    'message' => 'User not found'
                ], 404);
            }

            $userData = $user->toArray();

            $user->delete();

            // Audit log
            saveLog('USER_DELETE', 'User', $userData);

            return response()->json([
                'status' => 200,
                'message' => 'User deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
