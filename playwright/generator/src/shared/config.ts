export interface GeneratorConfig {
  loginPath: string;
  registerPath: string;
  dashboardPath: string;
  profilePath: string;
  forgotPasswordPath: string;
  resetPasswordPath: string;
}

export function getConfig(): GeneratorConfig {
  return {
    loginPath: process.env.LOGIN_PATH || '/login',
    registerPath: process.env.REGISTER_PATH || '/register',
    dashboardPath: process.env.DASHBOARD_PATH || '/dashboard',
    profilePath: process.env.PROFILE_PATH || '/profile',
    forgotPasswordPath: process.env.FORGOT_PASSWORD_PATH || '/forgot-password',
    resetPasswordPath: process.env.RESET_PASSWORD_PATH || '/reset-password',
  };
}
