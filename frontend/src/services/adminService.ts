import api from "../lib/api";
import type { Service } from "./queueService";

export type ServiceInput = Pick<
  Service,
  "name" | "description" | "average_service_minutes" | "is_active"
>;

export async function getAdminServices(): Promise<Service[]> {
  const response = await api.get<{ services: Service[] }>("/api/admin/services");
  return response.data.services;
}

export async function createService(data: ServiceInput): Promise<Service> {
  const response = await api.post<{ service: Service }>("/api/admin/services", data);
  return response.data.service;
}

export async function updateService(id: number, data: Partial<ServiceInput>): Promise<Service> {
  const response = await api.patch<{ service: Service }>(`/api/admin/services/${id}`, data);
  return response.data.service;
}
