import {
  createContext,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";

import {
  getCurrentUser,
  loginAccount,
  logoutAccount,
  registerAccount,
  type LoginData,
  type RegisterData,
  type User,
} from "../services/authService";

type AuthContextValue = {
  user: User | null;
  loading: boolean;

  registerUser: (
    data: RegisterData,
  ) => Promise<User>;

  loginUser: (
    data: LoginData,
  ) => Promise<User>;

  logoutUser: () => Promise<void>;
};

const AuthContext =
  createContext<AuthContextValue | undefined>(
    undefined,
  );

type AuthProviderProps = {
  children: ReactNode;
};

export function AuthProvider({
  children,
}: AuthProviderProps) {
  const [user, setUser] =
    useState<User | null>(null);

  const [loading, setLoading] =
    useState(true);

  useEffect(() => {
    async function loadUser() {
      try {
        const currentUser =
          await getCurrentUser();

        setUser(currentUser);
      } catch {
        setUser(null);
      } finally {
        setLoading(false);
      }
    }

    void loadUser();
  }, []);

  async function registerUser(
    data: RegisterData,
  ): Promise<User> {
    const registeredUser =
      await registerAccount(data);

    setUser(registeredUser);

    return registeredUser;
  }

  async function loginUser(
    data: LoginData,
  ): Promise<User> {
    const loggedInUser =
      await loginAccount(data);

    setUser(loggedInUser);

    return loggedInUser;
  }

  async function logoutUser(): Promise<void> {
    await logoutAccount();
    setUser(null);
  }

  return (
    <AuthContext.Provider
      value={{
        user,
        loading,
        registerUser,
        loginUser,
        logoutUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error(
      "useAuth must be used inside an AuthProvider.",
    );
  }

  return context;
}