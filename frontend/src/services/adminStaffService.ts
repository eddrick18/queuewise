import api from "../lib/api";

export type StaffAccount = {
  id: number;
  name: string;
  email: string;
  role: "staff";
  is_active: boolean;
};

export type StaffDetails = Pick<StaffAccount, "name" | "email" | "is_active">;
export type NewStaffAccount = StaffDetails & { password: string; password_confirmation: string };

export async function getStaffAccounts(): Promise<StaffAccount[]> {
  const response = await api.get<{ staff: StaffAccount[] }>("/api/admin/staff");
  return response.data.staff;
}

export async function createStaffAccount(data: NewStaffAccount): Promise<StaffAccount> {
  const response = await api.post<{ staff: StaffAccount }>("/api/admin/staff", data);
  return response.data.staff;
}

export async function updateStaffAccount(id: number, data: Partial<StaffDetails>): Promise<StaffAccount> {
  const response = await api.patch<{ staff: StaffAccount }>(`/api/admin/staff/${id}`, data);
  return response.data.staff;
}
