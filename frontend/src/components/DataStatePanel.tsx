import type { ReactNode } from 'react';
import { Card } from './Card';

interface DataStatePanelProps {
  title: string;
  description: string;
  action?: ReactNode;
  className?: string;
}

export function DataStatePanel({ title, description, action, className }: DataStatePanelProps) {
  return (
    <Card className={className}>
      <h2>{title}</h2>
      <p>{description}</p>
      {action}
    </Card>
  );
}
