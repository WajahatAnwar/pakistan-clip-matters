import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import MainLogo from '@/Images/main-logo.svg';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import LanguageSwitcher from '@/Components/LanguageSwitcher';

export default function Login({ status, canResetPassword }) {
    const { t } = useTranslation();
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>

            <Head title="Log in" />

            <div className="w-full max-w-md">
                <div className='justify-between align-center flex'>
                    <div>
                        <img src={MainLogo} alt="Pakistan Matters" className="w-48" />
                    </div>
                    <div className='margin-auto language-switcher-wrapper'>
                        <LanguageSwitcher />
                    </div>
                </div>
                <div className="flex flex-col gap-8">

                    <div className="flex items-center gap-2">
                        <h2 className="text-4xl font-bold text-white">{t('auth.welcomeToClipMatters')}</h2>
                    </div>

                    {status && (
                        <div className="text-sm font-medium text-green-400">
                            {status}
                        </div>
                    )}

                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <div>
                            <InputLabel htmlFor="email" value={t('auth.emailOrPhone')} className="text-white text-sm pb-2" />
                            <TextInput
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                placeholder={t('auth.emailPlaceholder')}
                                className="w-full rounded-md bg-[#2A303C] text-white placeholder-gray-400 px-4 py-2.5 border-none focus:ring-0"
                                autoComplete="username"
                                isFocused={true}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            <InputError message={errors.email} className="mt-2" />
                        </div>
                        <div>
                            <div className="flex justify-between items-center pb-2">
                                <InputLabel htmlFor="password" value={t('auth.password')} className="text-white text-sm" />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className="text-sm text-gray-400 hover:text-white flex items-center gap-1"
                                >
                                    {showPassword ? (
                                     
                                       
                                          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    ) : (
                                          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                        </svg>
                                    )}
                                    {showPassword ? t('auth.show') :t('auth.hide') }
                                </button>
                            </div>
                            <TextInput
                                id="password"
                                type={showPassword ? "text" : "password"}
                                name="password"
                                value={data.password}
                                className="w-full rounded-md bg-[#2A303C] text-white placeholder-gray-400 px-4 py-2.5 border-none focus:ring-0"
                                autoComplete="current-password"
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            <InputError message={errors.password} className="mt-2" />
                        </div>

                        <div className="flex items-center justify-between">
                            <label className="flex items-center">
                                <Checkbox
                                    name="remember"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                    className="rounded border-gray-600 bg-[#2A303C] text-red-600 focus:ring-0"
                                />
                                <span className="ml-2 text-sm text-gray-400">{t('auth.rememberMe')}</span>
                            </label>

                            {canResetPassword && (
                                <Link
                                    href={route('password.request')}
                                    className="text-sm text-gray-400 hover:text-white"
                                >
                                    {t('auth.forgetYourPassword')}
                                </Link>
                            )}
                        </div>

                        <div>
                            <PrimaryButton
                                className="w-full 
                                 bg-[#F01F32] hover:bg-[#e81b2c] rounded-full py-2.5 text-center font-normal text-xl text-white"
                                disabled={processing}
                            >
                                {t('auth.signIn')}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </GuestLayout>
    );
}
