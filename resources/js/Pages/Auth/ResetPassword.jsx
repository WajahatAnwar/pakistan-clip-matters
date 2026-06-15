import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function ResetPassword({ token, email }) {
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Reset Password" />

            <form onSubmit={submit} className="w-full max-w-md px-4">
                <div className="relative rounded-2xl border border-white/10 bg-white/5 p-6 shadow-[0_24px_70px_rgba(0,0,0,0.45)] backdrop-blur-md sm:p-8">
                    <div className="pointer-events-none absolute inset-x-10 -top-px h-px bg-gradient-to-r from-transparent via-[#F01F32] to-transparent" />

                    <div className="mb-6">
                        <p className="text-xs font-semibold uppercase tracking-[0.3em] text-white/50">
                            ClipMatters
                        </p>
                        <h1 className="mt-2 text-2xl font-semibold text-white sm:text-3xl">
                            Reset your password
                        </h1>
                        <p className="mt-2 text-sm text-white/60">
                            Set a strong password to keep your account secure.
                        </p>
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="email"
                            value="Email"
                            className="text-sm font-medium text-white/80"
                        />

                        <TextInput
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="mt-2 block w-full border border-white/10 bg-[#2E3239]/80 text-white placeholder:text-white/40 focus:!border-[#F01F32] focus:!ring-[#F01F32]"
                            autoComplete="username"
                            onChange={(e) => setData('email', e.target.value)}
                        />

                        <InputError message={errors.email} className="mt-2 text-red-300" />
                    </div>

                    <div className="mt-5">
                        <InputLabel
                            htmlFor="password"
                            value="Password"
                            className="text-sm font-medium text-white/80"
                        />

                        <div className="relative mt-2">
                            <TextInput
                                id="password"
                                type={showPassword ? 'text' : 'password'}
                                name="password"
                                value={data.password}
                                className="block w-full border border-white/10 bg-[#2E3239]/80 pr-12 text-white placeholder:text-white/40 focus:!border-[#F01F32] focus:!ring-[#F01F32]"
                                autoComplete="new-password"
                                isFocused={true}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword((prev) => !prev)}
                                className="absolute inset-y-0 right-3 flex items-center text-white/60 transition hover:text-white"
                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                            >
                                {showPassword ? (
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.8"
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    >
                                        <path d="M3 3l18 18" />
                                        <path d="M10.6 10.6a2.5 2.5 0 003.54 3.54" />
                                        <path d="M9.9 5.1A9.1 9.1 0 0112 5c5 0 9 5 9 7s-4 7-9 7a8.9 8.9 0 01-4.1-1" />
                                        <path d="M6.2 6.2C3.7 8 2 10.6 2 12c0 1.3 1.5 3.7 4 5.5" />
                                    </svg>
                                ) : (
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.8"
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    >
                                        <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                )}
                            </button>
                        </div>

                        <InputError message={errors.password} className="mt-2 text-red-300" />
                    </div>

                    <div className="mt-5">
                        <InputLabel
                            htmlFor="password_confirmation"
                            value="Confirm Password"
                            className="text-sm font-medium text-white/80"
                        />

                        <div className="relative mt-2">
                            <TextInput
                                type={showConfirmPassword ? 'text' : 'password'}
                                id="password_confirmation"
                                name="password_confirmation"
                                value={data.password_confirmation}
                                className="block w-full border border-white/10 bg-[#2E3239]/80 pr-12 text-white placeholder:text-white/40 focus:!border-[#F01F32] focus:!ring-[#F01F32]"
                                autoComplete="new-password"
                                onChange={(e) =>
                                    setData('password_confirmation', e.target.value)
                                }
                            />
                            <button
                                type="button"
                                onClick={() => setShowConfirmPassword((prev) => !prev)}
                                className="absolute inset-y-0 right-3 flex items-center text-white/60 transition hover:text-white"
                                aria-label={
                                    showConfirmPassword
                                        ? 'Hide confirm password'
                                        : 'Show confirm password'
                                }
                            >
                                {showConfirmPassword ? (
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.8"
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    >
                                        <path d="M3 3l18 18" />
                                        <path d="M10.6 10.6a2.5 2.5 0 003.54 3.54" />
                                        <path d="M9.9 5.1A9.1 9.1 0 0112 5c5 0 9 5 9 7s-4 7-9 7a8.9 8.9 0 01-4.1-1" />
                                        <path d="M6.2 6.2C3.7 8 2 10.6 2 12c0 1.3 1.5 3.7 4 5.5" />
                                    </svg>
                                ) : (
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.8"
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    >
                                        <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                )}
                            </button>
                        </div>

                        <InputError
                            message={errors.password_confirmation}
                            className="mt-2 text-red-300"
                        />
                    </div>

                    <div className="mt-6 flex items-center justify-end">
                        <PrimaryButton
                            className="w-full rounded-full bg-[#F01F32] px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-red-500/30 transition hover:bg-[#ff2e43] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#F01F32] disabled:cursor-not-allowed disabled:bg-[#F01F32]/60 sm:w-auto"
                            disabled={processing}
                        >
                            Reset Password
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
