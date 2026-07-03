import type { ReactNode } from 'react';
import { Card } from './Card';

interface DataStatePanelProps {
  title: string;
  description: string;
  action?: ReactNode;
}

export function DataStatePanel({ title, description, action }: DataStatePanelProps) {
  return (
    <Card className="emptyState">
      <h2>{title}</h2>
      <p>{description}</p>
      {action}
    </Card>
  );
}
